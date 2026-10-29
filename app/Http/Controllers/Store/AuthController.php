<?php

namespace App\Http\Controllers\Store;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Actions\CartResolver;
use App\Domain\Cart\Actions\MergeGuestCart;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\Store\RegisterCustomerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly MergeGuestCart $mergeCart) {}

    /**
     * Customer login with email or mobile number + password. Returns a bearer token.
     * A guest cart is merged into the customer's cart when its token is sent (`cart_token` in the body or the X-Cart-Token header).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = trim($request->input('login'));
        $field = str_contains($login, '@') ? 'email' : 'mobile';
        $value = $field === 'email' ? strtolower($login) : preg_replace('/\D/', '', $login);

        $user = User::where($field, $value)->first();

        if (! $user || ! $user->password || ! Hash::check($request->input('password'), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => ['The email/mobile or password is incorrect.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('customer')->plainTextToken,
            'user' => $this->userPayload($user),
            'cart_lines_merged' => $this->mergeCart->handle($user, $this->cartToken($request)),
        ]);
    }

    /** Create a customer account and log in (bearer token). A guest cart is merged like on login. */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::create([
            'full_name' => $data['full_name'], 'email' => strtolower($data['email']), 'mobile' => preg_replace('/\D/', '', $data['mobile']),
            'password' => $data['password'], 'professional_type' => $data['professional_type'] ?? null, 'is_active' => true,
        ]);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('customer')->plainTextToken,
            'user' => $this->userPayload($user),
            'cart_lines_merged' => $this->mergeCart->handle($user, $this->cartToken($request)),
        ], 201);
    }

    private function cartToken(Request $request): ?string
    {
        return $request->input('cart_token') ?: $request->header(CartResolver::HEADER);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'whatsapp' => $user->whatsapp,
            'professional_type' => $user->professional_type,
            'speciality' => $user->speciality,
            'gender' => $user->gender,
            'business_name' => $user->business_name,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'anniversary' => $user->anniversary?->toDateString(),
            'referral_code' => $user->referral_code,
            'verification_status' => $user->verification_status,
        ];
    }
}

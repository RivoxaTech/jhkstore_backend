<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\ShopTime;
use App\Support\TokenLifetime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Admin login with username + password.
     *
     * Two modes, chosen automatically:
     *  - Admin panel (browser on a stateful origin, see SANCTUM_STATEFUL_DOMAINS): logs in with an HttpOnly
     *    session cookie. Nothing secret is returned to JavaScript. Needs the CSRF cookie first.
     *  - Any other client (Postman, scripts): returns a bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $username = trim($request->input('login'));
        $password = $request->input('password');
        $invalid = fn () => ValidationException::withMessages(['login' => ['The username or password is incorrect.']]);

        if ($request->hasSession()) {
            if (! Auth::guard('admin')->attempt(['username' => $username, 'password' => $password, 'is_active' => true])) {
                throw $invalid();
            }
            $request->session()->regenerate();
            $admin = Auth::guard('admin')->user();
            $admin->forceFill(['last_login_at' => now()])->save();

            return response()->json(['admin' => $this->payload($admin)]);
        }

        $admin = Admin::where('username', $username)->first();
        if (! $admin || ! Hash::check($password, $admin->password) || ! $admin->is_active) {
            throw $invalid();
        }
        $admin->forceFill(['last_login_at' => now()])->save();

        $expiresAt = TokenLifetime::expiresAt('admin');

        return response()->json([
            'token' => $admin->createToken('admin', ['*'], $expiresAt)->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
            'admin' => $this->payload($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->hasSession() && Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            $request->user()->currentAccessToken()?->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['admin' => $this->payload($request->user())]);
    }

    private function payload(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'name' => $admin->name,
            'email' => $admin->email,
            'roles' => $admin->getRoleNames(),
            // what the panel uses to hide screens, tabs and buttons
            'permissions' => AdminAccess::effective($admin),
            // the store's time zone (IANA name): every date input and display in the panel uses it
            'timezone' => ShopTime::zone(),
        ];
    }
}

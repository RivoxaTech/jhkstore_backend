<?php

namespace App\Http\Controllers\Store;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Address\Models\UserAddress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\SaveAddressRequest;
use App\Http\Resources\AddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's address book. Every query is scoped to the logged-in customer: another customer's address answers 404. */
class AddressController extends Controller
{
    public function __construct(private readonly SaveAddress $addresses) {}

    /** All saved addresses, the default first. */
    public function index(Request $request)
    {
        return AddressResource::collection(
            UserAddress::query()->where('user_id', $request->user()->id)->orderByDesc('is_default')->orderByDesc('id')->limit(50)->get()
        );
    }

    public function store(SaveAddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user(), $request->validated());

        return (new AddressResource($address->refresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): AddressResource
    {
        return new AddressResource($this->mine($request, $id));
    }

    /** Update (send only the changed fields). `is_default: true` makes it the default. */
    public function update(SaveAddressRequest $request, int $id): AddressResource
    {
        return new AddressResource($this->addresses->update($this->mine($request, $id), $request->validated()));
    }

    public function makeDefault(Request $request, int $id): AddressResource
    {
        return new AddressResource($this->addresses->makeDefault($this->mine($request, $id)));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->addresses->delete($this->mine($request, $id));

        return response()->json(['message' => 'Address deleted.']);
    }

    private function mine(Request $request, int $id): UserAddress
    {
        return UserAddress::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}

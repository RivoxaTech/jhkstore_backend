<?php

namespace App\Domain\Address\Actions;

use App\Domain\Address\Models\UserAddress;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates, updates, deletes and sets the default of a customer's saved addresses. A customer always has exactly one default once
 * they have any address: the first address becomes it automatically, and when the default is deleted the newest one takes over.
 */
class SaveAddress
{
    public function create(User $user, array $data): UserAddress
    {
        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();   // serialises "first address" races

            $makeDefault = ! empty($data['is_default']) || ! UserAddress::query()->where('user_id', $user->id)->exists();
            if ($makeDefault) {
                UserAddress::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return UserAddress::create(['user_id' => $user->id, 'is_default' => $makeDefault] + $this->clean($data));
        });
    }

    public function update(UserAddress $address, array $data): UserAddress
    {
        return DB::transaction(function () use ($address, $data) {
            if (! empty($data['is_default']) && ! $address->is_default) {
                UserAddress::query()->where('user_id', $address->user_id)->update(['is_default' => false]);
                $address->is_default = true;
            }
            // an address cannot lose its default flag by itself: choose another one as default instead
            $address->fill($this->clean($data))->save();

            return $address;
        });
    }

    public function makeDefault(UserAddress $address): UserAddress
    {
        return $this->update($address, ['is_default' => true]);
    }

    public function delete(UserAddress $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                UserAddress::query()->where('user_id', $address->user_id)->latest('id')->limit(1)->update(['is_default' => true]);
            }
        });
    }

    /** Only the address fields (never user_id / is_default from the request), with empty optional strings stored as null. */
    private function clean(array $data): array
    {
        $out = [];
        foreach (['label', 'type', 'name', 'phone', 'alt_phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country', 'gst_number', 'latitude', 'longitude'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                $out[$key] = $value === '' ? null : $value;
            }
        }
        $out['type'] ??= null;
        if ($out['type'] === null) {
            unset($out['type']);
        }
        if (array_key_exists('country', $out) && $out['country'] === null) {
            unset($out['country']);
        }

        return $out;
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Address\Models\UserAddress;
use Illuminate\Validation\Rule;

/**
 * Rules and messages of the address form, shared by the address book and the inline address of checkout.
 * `$prefix` is '' for the address book and 'address.' for the inline address.
 * Nothing here is specific to one country: the tax number is free text and the postcode only needs to look like one.
 */
class AddressPayloadRules
{
    private const PHONE = '/^\+?[0-9][0-9\s\-]{6,18}$/';

    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'name' => [$req, 'string', 'max:120'],
            $prefix.'phone' => [$req, 'string', 'regex:'.self::PHONE],
            $prefix.'alt_phone' => ['nullable', 'string', 'regex:'.self::PHONE],
            $prefix.'line1' => [$req, 'string', 'max:255'],
            $prefix.'area' => [$req, 'string', 'max:255'],
            $prefix.'landmark' => ['nullable', 'string', 'max:160'],
            $prefix.'city' => [$req, 'string', 'max:100'],
            $prefix.'state' => [$req, 'string', 'max:100'],
            $prefix.'postcode' => [$req, 'string', 'regex:/^[A-Za-z0-9\- ]{3,10}$/'],
            $prefix.'country' => ['nullable', 'string', 'max:60'],
            $prefix.'tax_number' => ['nullable', 'string', 'max:40'],
            $prefix.'type' => [$partial ? 'sometimes' : 'nullable', Rule::in(UserAddress::TYPES)],
            $prefix.'label' => ['nullable', 'string', 'max:40'],
            $prefix.'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:'.$prefix.'longitude'],
            $prefix.'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:'.$prefix.'latitude'],
        ];
    }

    public static function messages(string $prefix = ''): array
    {
        $msgs = [
            'name.required' => 'Enter the full name.',
            'phone.required' => 'Enter a phone number.',
            'phone.regex' => 'Enter a valid phone number (digits, optionally starting with +).',
            'alt_phone.regex' => 'Enter a valid alternate phone number.',
            'line1.required' => 'Enter the house no. / building / apartment.',
            'area.required' => 'Enter the area / street.',
            'city.required' => 'Enter the city.',
            'state.required' => 'Enter the state or province.',
            'postcode.required' => 'Enter the postcode.',
            'postcode.regex' => 'Enter a valid postcode (3 to 10 letters or digits).',
            'type.in' => 'The address type must be home, work or other.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'latitude.required_with' => 'Send both latitude and longitude.',
            'longitude.required_with' => 'Send both latitude and longitude.',
        ];

        return $prefix === '' ? $msgs : collect($msgs)->mapWithKeys(fn ($m, $k) => [$prefix.$k => $m])->all();
    }

    /** Values are trimmed and empty optional ones become null. */
    public static function normalise(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }
        foreach (['alt_phone', 'tax_number', 'landmark', 'label'] as $optional) {
            if (($data[$optional] ?? null) === '') {
                $data[$optional] = null;
            }
        }

        return $data;
    }
}

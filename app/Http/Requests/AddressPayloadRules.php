<?php

namespace App\Http\Requests;

use App\Domain\Address\Models\UserAddress;
use Illuminate\Validation\Rule;

/**
 * Rules and messages of the address form (the Dentalkart "edit address" form), shared by the address book and the inline address of checkout.
 * `$prefix` is '' for the address book and 'address.' for the inline address.
 */
class AddressPayloadRules
{
    private const PHONE = '/^\+?[0-9][0-9\s\-]{6,18}$/';
    private const GSTIN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

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
            $prefix.'gst_number' => ['nullable', 'string', 'regex:'.self::GSTIN],
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
            'line1.required' => 'Enter the clinic name / house no. / building / apartment.',
            'area.required' => 'Enter the area / street / sector / village.',
            'city.required' => 'Enter the city.',
            'state.required' => 'Enter the state.',
            'postcode.required' => 'Enter the postcode.',
            'postcode.regex' => 'Enter a valid postcode (3 to 10 letters or digits).',
            'gst_number.regex' => 'Enter a valid 15-character GSTIN, for example 27ABCDE1234F1Z5.',
            'type.in' => 'The address type must be clinic, home or other.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'latitude.required_with' => 'Send both latitude and longitude.',
            'longitude.required_with' => 'Send both latitude and longitude.',
        ];

        return $prefix === '' ? $msgs : collect($msgs)->mapWithKeys(fn ($m, $k) => [$prefix.$k => $m])->all();
    }

    /** GSTIN letters are upper-case; spaces around values are removed. */
    public static function normalise(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }
        if (isset($data['gst_number']) && $data['gst_number'] !== '') {
            $data['gst_number'] = strtoupper($data['gst_number']);
        }
        foreach (['alt_phone', 'gst_number', 'landmark', 'label'] as $optional) {
            if (($data[$optional] ?? null) === '') {
                $data[$optional] = null;
            }
        }

        return $data;
    }
}

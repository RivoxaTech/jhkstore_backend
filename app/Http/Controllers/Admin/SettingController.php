<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public const GROUPS = [
        'general' => 'Store profile',
        'currency' => 'Currency',
        'tax' => 'Tax',
        'orders' => 'Orders',
        'shipping' => 'Delivery',
        'features' => 'Features',
        'rewards' => 'Reward points',
    ];

    /** Every setting, grouped, with its type, label, help text and current value (the Settings page is built from this). */
    public function show(Settings $settings): JsonResponse
    {
        return response()->json($this->payload($settings));
    }

    /** Partial update: send only the keys that change. Unknown keys are rejected. */
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $unknown = array_diff(array_keys($request->all()), array_keys(Settings::DEFINITIONS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['Unknown setting.']));
        }

        $data = $request->validate($this->rules(array_keys($request->all())));
        $settings->set($data);
        Audit::log($request->user(), 'settings.updated', null, $data);

        return response()->json($this->payload($settings));
    }

    public function uploadLogo(Request $request, Settings $settings): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        $this->deleteLogoFile($settings);
        $path = $request->file('logo')->store('branding', 'public');
        $settings->set(['store_logo' => $path]);
        Audit::log($request->user(), 'settings.logo_updated');

        return response()->json($this->payload($settings));
    }

    public function deleteLogo(Request $request, Settings $settings): JsonResponse
    {
        $this->deleteLogoFile($settings);
        $settings->set(['store_logo' => '']);
        Audit::log($request->user(), 'settings.logo_removed');

        return response()->json($this->payload($settings));
    }

    private function deleteLogoFile(Settings $settings): void
    {
        if ($path = (string) $settings->get('store_logo')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function payload(Settings $settings): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'fields' => []];
        }
        foreach (Settings::DEFINITIONS as $key => $def) {
            $groups[$def[0]]['fields'][] = [
                'key' => $key, 'type' => $def[1], 'label' => $def[3], 'help' => $def[4] ?? null,
                'min' => $def[5] ?? null, 'max' => $def[6] ?? null, 'value' => $settings->get($key),
            ];
        }

        return [
            'groups' => array_values($groups),
            'values' => $settings->all(),
            'logo_url' => $settings->logoUrl(),
        ];
    }

    /** Validation rules by setting type, only for the keys that were sent. */
    private function rules(array $keys): array
    {
        $rules = [];
        foreach ($keys as $key) {
            $def = Settings::DEFINITIONS[$key];
            $rules[$key] = match ($def[1]) {
                'bool' => ['required', 'boolean'],
                'int' => ['required', 'integer', 'min:'.($def[5] ?? 0), 'max:'.($def[6] ?? 100000000)],
                'money' => ['required', 'numeric', 'min:0', 'max:1000000000'],
                'percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'text' => ['nullable', 'string', 'max:1000'],
                'file' => ['prohibited'],
                default => ['nullable', 'string', 'max:255'],
            };
        }
        $overrides = [
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['nullable', 'email', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'order_number_prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'tax_label' => ['required', 'string', 'max:30'],
            'reward_redeem_rate' => ['required', 'numeric', 'min:0.01', 'max:100000'],
        ];

        return array_intersect_key($overrides, $rules) + $rules;
    }
}

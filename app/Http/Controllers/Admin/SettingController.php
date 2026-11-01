<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    /** The Settings page: tab => [label, the setting groups (sections) it shows]. */
    public const TABS = [
        'general' => ['General', ['general']],
        'branding' => ['Branding', ['branding']],
        'footer' => ['Footer & Social', ['footer']],
        'currency_tax' => ['Currency & Tax', ['currency', 'tax']],
        'orders_delivery' => ['Orders & Delivery', ['orders', 'shipping']],
        'features' => ['Features', ['features', 'rewards']],
        'seo' => ['SEO', ['seo']],
    ];

    public const SECTIONS = [
        'general' => 'Store profile',
        'branding' => 'Logo and favicon',
        'footer' => 'Footer and social links',
        'currency' => 'Currency',
        'tax' => 'Tax',
        'orders' => 'Orders',
        'shipping' => 'Delivery',
        'features' => 'Features',
        'rewards' => 'Reward points',
        'seo' => 'Search engines and sharing',
    ];

    /** Upload slot (the URL segment) => [setting key, request field, accepted extensions, max size in KB, largest side in px (null = not checked)]. SVG is not accepted: it can carry scripts. */
    private const FILES = [
        'logo' => ['store_logo', 'logo', ['png', 'jpg', 'jpeg', 'webp'], 2048, 4000],
        'favicon' => ['store_favicon', 'favicon', ['png', 'ico', 'jpg', 'jpeg', 'webp'], 512, null],
        'share-image' => ['seo_share_image', 'share_image', ['png', 'jpg', 'jpeg', 'webp'], 4096, 6000],
    ];

    /** Every setting, by tab and section, with its type, label, help text and current value (the Settings page is built from this). */
    public function show(Settings $settings): JsonResponse
    {
        return response()->json($this->payload($settings));
    }

    /** Partial update: send only the keys that change. Unknown keys are rejected; pictures have their own endpoints. */
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $unknown = array_diff(array_keys($request->all()), array_keys(Settings::DEFINITIONS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['Unknown setting.']));
        }

        $keys = array_keys($request->all());
        $data = $request->validate($settings->rules($keys), $settings->messages($keys), $settings->attributes($keys));
        $settings->set($data);
        Audit::log($request->user(), 'settings.updated', null, $data);

        return response()->json($this->payload($settings));
    }

    /** POST settings/{logo|favicon|share-image}: replaces the picture (image type and size are validated). */
    public function uploadFile(Request $request, Settings $settings): JsonResponse
    {
        [$key, $field, $extensions, $maxKb, $maxSide] = self::FILES[$request->route('slot')];
        $request->validate([$field => array_filter([
            'required',
            in_array('ico', $extensions, true) ? 'file' : 'image',      // the image rule does not know .ico
            'mimes:'.implode(',', $extensions),
            'max:'.$maxKb,
            $maxSide ? "dimensions:max_width=$maxSide,max_height=$maxSide" : null,
        ])]);

        $settings->attachFile($key, $request->file($field));
        Audit::log($request->user(), 'settings.file_updated', null, ['setting' => $key]);

        return response()->json($this->payload($settings));
    }

    /** DELETE settings/{logo|favicon|share-image} */
    public function deleteFile(Request $request, Settings $settings): JsonResponse
    {
        [$key] = self::FILES[$request->route('slot')];
        $settings->removeFile($key);
        Audit::log($request->user(), 'settings.file_removed', null, ['setting' => $key]);

        return response()->json($this->payload($settings));
    }

    private function payload(Settings $settings): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            $sections[$key] = ['key' => $key, 'label' => $label, 'fields' => []];
        }
        $files = [];
        $uploads = [];
        foreach (self::FILES as $slot => [$key, $field, $extensions, $maxKb]) {
            $uploads[$key] = ['slot' => $slot, 'field' => $field, 'extensions' => $extensions, 'max_kb' => $maxKb];
        }
        foreach (Settings::DEFINITIONS as $key => $def) {
            $sections[$def[0]]['fields'][] = [
                'key' => $key, 'type' => $def[1], 'label' => $def[3], 'help' => $def[4] ?? null,
                'min' => $def[5] ?? null, 'max' => $def[6] ?? null, 'value' => $def[1] === 'file' ? null : $settings->get($key),
            ] + ($def[1] === 'file' ? ['upload' => $uploads[$key]] : []);
            if ($def[1] === 'file') {
                $files[$key] = $settings->fileUrl($key);
            }
        }

        $tabs = [];
        foreach (self::TABS as $key => [$label, $groups]) {
            $tabs[] = ['key' => $key, 'label' => $label, 'sections' => array_map(fn ($g) => $sections[$g], $groups)];
        }

        return [
            'tabs' => $tabs,
            'values' => array_diff_key($settings->all(), $files),
            'files' => $files,
            'link_platforms' => Settings::LINK_PLATFORMS,
        ];
    }
}

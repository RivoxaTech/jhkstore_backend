<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Domain\Auth\Models\Admin;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    /**
     * The Settings page: tab => [label, the setting groups (sections) it shows]. Every tab is guarded by its own permission `settings.<tab>`
     * (see AdminAccess). The `banners` tab has no settings: the panel fills it with the banner screens (/admin/banners endpoints); the `delivery` tab
     * also holds the delivery zone manager (/admin/delivery-zones endpoints). `theme` is the developer's.
     * To add a tab later (e.g. `payment_details`): add a line here, the groups in Settings::DEFINITIONS, a section below and the
     * permission `settings.payment_details` in AdminAccess::GROUPS.
     */
    public const TABS = [
        'general' => ['General', ['general']],
        'branding' => ['Branding', ['branding']],
        'banners' => ['Banners', []],
        'footer' => ['Footer & Social', ['footer']],
        'currency_tax' => ['Currency & Tax', ['currency', 'tax']],
        'orders_delivery' => ['Orders & Delivery', ['orders']],
        'delivery' => ['Delivery', ['delivery']],
        'payments' => ['Payments', ['payments']],
        'store' => ['Store', ['store']],
        'theme' => ['Theme', ['theme']],
        'seo' => ['SEO', ['seo']],
    ];

    public const SECTIONS = [
        'general' => 'Store profile',
        'branding' => 'Logo and favicon',
        'footer' => 'Footer and social links',
        'currency' => 'Currency',
        'tax' => 'Tax',
        'orders' => 'Orders',
        'delivery' => 'Delivery',
        'payments' => 'Payment methods',
        'store' => 'Store status and notices',
        'theme' => 'Colours, fonts and corners of the storefront',
        'seo' => 'Search engines and sharing',
    ];

    /** Upload slot (the URL segment) => [setting key, request field, accepted extensions, max size in KB, largest side in px (null = not checked)]. SVG is not accepted: it can carry scripts. The slot's tab (branding / seo) decides who may upload. */
    private const FILES = [
        'logo' => ['store_logo', 'logo', ['png', 'jpg', 'jpeg', 'webp'], 2048, 4000],
        'favicon' => ['store_favicon', 'favicon', ['png', 'ico', 'jpg', 'jpeg', 'webp'], 512, null],
        'share-image' => ['seo_share_image', 'share_image', ['png', 'jpg', 'jpeg', 'webp'], 4096, 6000],
    ];

    /** Every setting, by tab and section, with its type, label, help text and current value (the Settings page is built from this). */
    public function show(Request $request, Settings $settings): JsonResponse
    {
        return response()->json($this->payload($settings, $request->user()));
    }

    /** Partial update: send only the keys that change. Unknown keys are rejected; pictures have their own endpoints. */
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $unknown = array_diff(array_keys($request->all()), array_keys(Settings::DEFINITIONS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['Unknown setting.']));
        }

        $keys = array_keys($request->all());
        // each key belongs to a tab; the admin needs the permission of every tab it touches
        foreach ($keys as $key) {
            abort_unless($this->mayEdit($request->user(), $key), 403, 'You do not have permission to change this setting.');
        }
        $data = $request->validate($settings->rules($keys), $settings->messages($keys), $settings->attributes($keys));
        $this->checkCombination($settings, $data);
        $settings->set($data);
        Audit::log($request->user(), 'settings.updated', null, $data);

        return response()->json($this->payload($settings, $request->user()));
    }

    /** POST settings/{logo|favicon|share-image}: replaces the picture (image type and size are validated). */
    public function uploadFile(Request $request, Settings $settings): JsonResponse
    {
        [$key, $field, $extensions, $maxKb, $maxSide] = self::FILES[$request->route('slot')];
        abort_unless($this->mayEdit($request->user(), $key), 403, 'You do not have permission to change this setting.');
        $request->validate([$field => array_filter([
            'required',
            in_array('ico', $extensions, true) ? 'file' : 'image',      // the image rule does not know .ico
            'mimes:'.implode(',', $extensions),
            'max:'.$maxKb,
            $maxSide ? "dimensions:max_width=$maxSide,max_height=$maxSide" : null,
        ])]);

        $settings->attachFile($key, $request->file($field));
        Audit::log($request->user(), 'settings.file_updated', null, ['setting' => $key]);

        return response()->json($this->payload($settings, $request->user()));
    }

    /** DELETE settings/{logo|favicon|share-image} */
    public function deleteFile(Request $request, Settings $settings): JsonResponse
    {
        [$key] = self::FILES[$request->route('slot')];
        abort_unless($this->mayEdit($request->user(), $key), 403, 'You do not have permission to change this setting.');
        $settings->removeFile($key);
        Audit::log($request->user(), 'settings.file_removed', null, ['setting' => $key]);

        return response()->json($this->payload($settings, $request->user()));
    }

    /**
     * Rules that need more than one setting (the values that are sent are merged over the stored ones): at least one payment method stays on,
     * and bank transfer needs at least one active account to pay into.
     */
    private function checkCombination(Settings $settings, array $data): void
    {
        $paymentKeys = ['pay_cod_enabled', 'pay_bank_enabled', 'pay_bank_accounts'];
        if (array_intersect($paymentKeys, array_keys($data)) === []) {
            return;
        }
        $value = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $settings->get($key);
        $cod = filter_var($value('pay_cod_enabled'), FILTER_VALIDATE_BOOLEAN);
        $bank = filter_var($value('pay_bank_enabled'), FILTER_VALIDATE_BOOLEAN);
        $blame = fn (string $first, string $second) => array_key_exists($first, $data) ? $first : $second;

        if (! $cod && ! $bank) {
            $key = $blame('pay_cod_enabled', 'pay_bank_enabled');
            throw ValidationException::withMessages([$key => ['Keep at least one payment method on: cash on delivery or bank transfer.']]);
        }
        $active = collect($settings->accountsFrom($value('pay_bank_accounts')))->where('active', true);
        if ($bank && $active->isEmpty()) {
            $key = $blame('pay_bank_enabled', 'pay_bank_accounts');
            throw ValidationException::withMessages([$key => ['Add at least one active bank account before you turn bank transfer on.']]);
        }
    }

    /** The tab a setting is shown in. */
    private function tabOf(string $key): string
    {
        $group = Settings::DEFINITIONS[$key][0];
        foreach (self::TABS as $tab => [, $groups]) {
            if (in_array($group, $groups, true)) {
                return $tab;
            }
        }
        throw new \LogicException("Setting [$key] is not shown in any tab.");
    }

    private function mayEdit(Admin $admin, string $key): bool
    {
        return AdminAccess::allows($admin, 'settings.'.$this->tabOf($key));
    }

    /** Only the tabs (and their values) the admin may open are sent. */
    private function payload(Settings $settings, Admin $admin): array
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
            ] + ($def[1] === 'file' ? ['upload' => $uploads[$key]] : []) + ($def[1] === 'choice' ? ['options' => Settings::CHOICES[$key]] : []);
            if ($def[1] === 'file') {
                $files[$key] = $settings->fileUrl($key);
            }
        }

        $tabs = [];
        foreach (self::TABS as $key => [$label, $groups]) {
            if (AdminAccess::allows($admin, 'settings.'.$key)) {
                $tabs[] = ['key' => $key, 'label' => $label, 'sections' => array_map(fn ($g) => $sections[$g], $groups)];
            }
        }
        $visible = fn (string $k) => AdminAccess::allows($admin, 'settings.'.$this->tabOf($k));

        return [
            'tabs' => $tabs,
            'values' => array_filter(array_diff_key($settings->all(), $files), fn ($v, $k) => $visible($k), ARRAY_FILTER_USE_BOTH),
            'files' => array_filter($files, fn ($v, $k) => $visible($k), ARRAY_FILTER_USE_BOTH),
            'link_platforms' => Settings::LINK_PLATFORMS,
        ];
    }
}

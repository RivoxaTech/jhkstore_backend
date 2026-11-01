<?php

namespace App\Domain\Support;

use App\Domain\Support\Models\Setting;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The one place that reads and writes store settings (table `settings`, key/value, cached).
 *
 * Every setting is declared in DEFINITIONS with its group, type, default and label, so a missing row falls back to the default,
 * the admin Settings page can be built from the list, and writes are validated by type. Client values (store name, currency, tax, …)
 * live here, not in code. Feature switches (`rewards_enabled`) are read through the helpers at the bottom,
 * so every module checks them in the same way.
 *
 * Types: string | text | bool | int | money | percent | file | links
 *  - `file`  a picture owned by the setting's row (medialibrary); the value is the media id, uploaded through the admin endpoints
 *  - `links` a JSON list of {platform, label, url, active}; the order of the list is the display order
 */
class Settings
{
    public const CACHE_KEY = 'settings.all';

    public const FILES_CACHE_KEY = 'settings.files';

    /** Platforms a footer / social link can have. `custom` is any other site. */
    public const LINK_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok', 'whatsapp', 'pinterest', 'website', 'custom'];

    /** key => [group, type, default, label, help?, min?, max?] */
    public const DEFINITIONS = [
        // --- store profile
        'store_name' => ['general', 'string', 'My Store', 'Store name'],
        'store_tagline' => ['general', 'string', '', 'Tagline'],
        'store_email' => ['general', 'string', '', 'Contact email'],
        'store_phone' => ['general', 'string', '', 'Contact phone'],
        'store_address' => ['general', 'text', '', 'Address'],
        'store_country' => ['general', 'string', '', 'Country', 'Used as the default country of addresses.'],

        // --- branding (pictures are uploaded, not typed)
        'store_logo' => ['branding', 'file', '', 'Logo', 'PNG, JPG or WEBP, up to 2 MB.'],
        'store_favicon' => ['branding', 'file', '', 'Favicon', 'PNG, ICO, JPG or WEBP, up to 512 KB. A square picture works best.'],

        // --- footer and social links
        'social_links' => ['footer', 'links', [], 'Social and footer links', 'Any number of links, shown in this order.'],
        'footer_text' => ['footer', 'text', '', 'Footer text', 'A short text for the footer, e.g. what the store sells.'],
        'footer_copyright' => ['footer', 'string', '', 'Copyright text', 'e.g. © 2026 My Store. All rights reserved.'],
        'support_hours' => ['footer', 'string', '', 'Support hours', 'e.g. Mon-Fri 9:00-18:00.'],
        'whatsapp_number' => ['footer', 'string', '', 'WhatsApp number', 'With the country code, e.g. +49 170 1234567.'],

        // --- search engines and sharing
        'seo_meta_title' => ['seo', 'string', '', 'Default meta title', 'Used by pages that have no title of their own.'],
        'seo_meta_description' => ['seo', 'text', '', 'Default meta description', 'About 150 characters.'],
        'seo_share_image' => ['seo', 'file', '', 'Default share image', 'Shown when a page is shared (PNG, JPG or WEBP, up to 4 MB; 1200x630 is ideal).'],

        // --- currency and tax
        'currency_code' => ['currency', 'string', 'USD', 'Currency code', 'ISO code, e.g. USD, EUR, GBP.'],
        'currency_symbol' => ['currency', 'string', '$', 'Currency symbol'],
        'currency_decimals' => ['currency', 'int', 2, 'Decimals', 'Decimal places shown and calculated (0, 1 or 2).', 0, 2],
        'tax_rate' => ['tax', 'percent', 0, 'Default tax rate (%)', 'Used for products without their own rate. 0 = no tax.'],
        'tax_inclusive' => ['tax', 'bool', true, 'Prices include tax', 'On: tax is already inside the prices. Off: tax is added at the cart.'],
        'tax_label' => ['tax', 'string', 'Tax', 'Tax label', 'Shown to customers, e.g. VAT, GST, Sales tax.'],

        // --- orders and delivery
        'order_number_prefix' => ['orders', 'string', 'ORD', 'Order number prefix', 'Order numbers look like PREFIX-2026-000123.'],
        'min_order_amount' => ['orders', 'money', 0, 'Minimum order amount', '0 = no minimum.'],
        'cod_max_amount' => ['orders', 'money', 0, 'Cash on delivery limit', '0 = no limit.'],
        'return_window_days' => ['orders', 'int', 10, 'Return window (days)', 'Default return period of products.'],
        'delivery_fee' => ['shipping', 'money', 0, 'Delivery charge', 'One flat charge per order. 0 = free delivery.'],
        'free_delivery_min_cart' => ['shipping', 'money', 0, 'Free delivery from', 'Cart value from which delivery is free. 0 = never free.'],
        'delivery_days' => ['shipping', 'int', 5, 'Delivery time (days)', 'Shown to customers as the delivery estimate.', 0, 60],

        // --- optional features
        'rewards_enabled' => ['features', 'bool', false, 'Enable reward points', 'Off: no points are earned, shown or redeemed anywhere.'],

        // --- reward points (only used when `rewards_enabled` is on)
        'reward_earn_rate' => ['rewards', 'money', 0, 'Earn rate', 'Points earned per 1 currency unit spent, for products without their own points. 0 = none.'],
        'reward_redeem_rate' => ['rewards', 'money', 1, 'Redeem rate', 'Money value of one point at checkout.'],
        'reward_expiry_days' => ['rewards', 'int', 365, 'Points expire after (days)', null, 1, 3650],
        'max_redeem_coins_per_order' => ['rewards', 'int', 5000, 'Most points per order'],
        'redeem_cap_order_percent' => ['rewards', 'percent', 100, 'Most % of an order points may pay', '100 = no extra cap.'],
        'redeem_cap_balance_percent' => ['rewards', 'percent', 100, 'Most % of the balance one order may use', '100 = no extra cap.'],
    ];

    /** @var array<string,mixed>|null per-request copy of the stored values */
    private ?array $stored = null;

    /** @var array<int,string>|null media id => absolute URL of every `file` setting picture */
    private ?array $fileUrls = null;

    /** Typed value of a setting (the stored one, or the declared default). */
    public function get(string $key): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting [$key].");
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $this->cast($def[1], $stored[$key]) : $def[2];
    }

    /** @return array<string,mixed> every setting, typed, by key */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** @param  array<string,mixed>  $values  unknown keys are ignored */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }
            [$group, $type] = self::DEFINITIONS[$key];
            Setting::query()->updateOrCreate(['key' => $key], ['group_key' => $group, 'value' => $this->cast($type, $value)]);
        }
        $this->flush();
    }

    /** Writes the defaults of settings that have no row yet (admin edits are kept). */
    public function seedDefaults(): void
    {
        $have = array_keys($this->stored());
        foreach (self::DEFINITIONS as $key => [$group, , $default]) {
            if (! in_array($key, $have, true)) {
                Setting::query()->create(['key' => $key, 'group_key' => $group, 'value' => $default]);
            }
        }
        $this->flush();
    }

    public function flush(): void
    {
        $this->stored = null;
        $this->fileUrls = null;
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::FILES_CACHE_KEY);
    }

    // ------------------------------------------------------------------ feature switches (the single place they are read)

    public function rewardsEnabled(): bool
    {
        return (bool) $this->get('rewards_enabled');
    }

    // ------------------------------------------------------------------ money (whole minor units, so there are no float surprises)

    public function currencyCode(): string
    {
        return (string) $this->get('currency_code');
    }

    public function currencySymbol(): string
    {
        return (string) $this->get('currency_symbol');
    }

    public function decimals(): int
    {
        return max(0, min(2, (int) $this->get('currency_decimals')));
    }

    public function logoUrl(): ?string
    {
        return $this->fileUrl('store_logo');
    }

    // ------------------------------------------------------------------ files and links

    /** Absolute URL of the picture of a `file` setting, or null when none is uploaded. */
    public function fileUrl(string $key): ?string
    {
        $id = (int) $this->get($key);

        return $id > 0 ? ($this->fileUrls()[$id] ?? null) : null;
    }

    /** Replaces the picture of a `file` setting (the old file is deleted). Validate the upload before calling. */
    public function attachFile(string $key, UploadedFile $file): void
    {
        [$group, $type] = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting [$key].");
        if ($type !== 'file') {
            throw new \InvalidArgumentException("Setting [$key] is not a file.");
        }

        $row = Setting::query()->firstOrCreate(['key' => $key], ['group_key' => $group, 'value' => '']);
        $media = $row->addMedia($file)->toMediaCollection('file');   // single-file collection: the previous picture is removed
        $this->set([$key => (string) $media->id]);
    }

    public function removeFile(string $key): void
    {
        Setting::query()->where('key', $key)->first()?->clearMediaCollection('file');
        $this->set([$key => '']);
    }

    /** The social / footer links the storefront shows: active ones only, in order, with a label. */
    public function publicLinks(): array
    {
        return collect($this->get('social_links'))->where('active', true)->map(fn ($l) => [
            'platform' => $l['platform'],
            'label' => $l['label'] !== '' ? $l['label'] : ($l['platform'] === 'x' ? 'X' : ucfirst($l['platform'])),
            'url' => $l['url'],
        ])->values()->all();
    }

    /** Names used in validation messages: "The delivery time (days) field must not be greater than 60." */
    public function attributes(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = lcfirst(self::DEFINITIONS[$key][3]);
            if (self::DEFINITIONS[$key][1] === 'links') {
                $out["$key.*.platform"] = 'platform';
                $out["$key.*.label"] = 'label';
                $out["$key.*.url"] = 'link address';
                $out["$key.*.active"] = 'active switch';
            }
        }

        return $out;
    }

    /** Clearer messages for the rows of a `links` setting. */
    public function messages(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (self::DEFINITIONS[$key][1] === 'links') {
                $out["$key.*.url.required"] = 'Enter the link address.';
                $out["$key.*.url.url"] = 'Enter a full web address starting with http:// or https://.';
                $out["$key.*.platform.in"] = 'Choose one of the platforms.';
                $out["$key.*.label.max"] = 'The label can have at most 60 characters.';
            }
        }

        return $out;
    }

    /** Validation rules by setting type for the keys that are sent (the admin API uses them; the service itself does not validate on set). */
    public function rules(array $keys): array
    {
        $rules = [];
        foreach ($keys as $key) {
            $def = self::DEFINITIONS[$key];
            $rules[$key] = match ($def[1]) {
                'bool' => ['required', 'boolean'],
                'int' => ['required', 'integer', 'min:'.($def[5] ?? 0), 'max:'.($def[6] ?? 100000000)],
                'money' => ['required', 'numeric', 'min:0', 'max:1000000000'],
                'percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'text' => ['nullable', 'string', 'max:1000'],
                'file' => ['prohibited'],                      // only through the upload endpoints
                'links' => ['present', 'array'],
                default => ['nullable', 'string', 'max:255'],
            };
            if ($def[1] === 'links') {
                $rules["$key.*.platform"] = ['required', 'string', 'in:'.implode(',', self::LINK_PLATFORMS)];
                $rules["$key.*.label"] = ['nullable', 'string', 'max:60'];
                $rules["$key.*.url"] = ['required', 'url:http,https', 'max:500'];   // no javascript: / data: links
                $rules["$key.*.active"] = ['nullable', 'boolean'];
            }
        }
        $overrides = [
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['nullable', 'email', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'order_number_prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'tax_label' => ['required', 'string', 'max:30'],
            'reward_redeem_rate' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'whatsapp_number' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9][0-9 ()-]{4,23}$/'],
            'seo_meta_title' => ['nullable', 'string', 'max:70'],
            'seo_meta_description' => ['nullable', 'string', 'max:300'],
            'footer_text' => ['nullable', 'string', 'max:500'],
        ];

        return array_intersect_key($overrides, $rules) + $rules;
    }

    public function deliveryFeeCents(): int
    {
        return $this->cents($this->get('delivery_fee'));
    }

    public function freeDeliveryMinCents(): int
    {
        return $this->cents($this->get('free_delivery_min_cart'));
    }

    public function minOrderCents(): int
    {
        return $this->cents($this->get('min_order_amount'));
    }

    public function codMaxCents(): int
    {
        return $this->cents($this->get('cod_max_amount'));
    }

    public function rewardRedeemCents(): int
    {
        return max(1, $this->cents($this->get('reward_redeem_rate')));
    }

    public function rewardExpiryDays(): int
    {
        return max(1, (int) $this->get('reward_expiry_days'));
    }

    /** Amount → minor units for the configured number of decimals. */
    public function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * (10 ** $this->decimals()));
    }

    /** Minor units → amount. */
    public function money(int $cents): float
    {
        return round($cents / (10 ** $this->decimals()), $this->decimals());
    }

    // ------------------------------------------------------------------ internals

    private function stored(): array
    {
        return $this->stored ??= Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
    }

    /** @return array<int,string> */
    private function fileUrls(): array
    {
        return $this->fileUrls ??= Cache::rememberForever(self::FILES_CACHE_KEY, fn () => Media::query()
            ->where('model_type', (new Setting)->getMorphClass())->get()
            ->mapWithKeys(fn (Media $m) => [$m->id => MediaUrl::media($m)])->all());
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'links' => $this->castLinks($value),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'money', 'percent' => round((float) $value, 2),
            default => $value === null ? '' : (string) $value,
        };
    }

    /** Keeps only well-formed rows, in the given order. */
    private function castLinks(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row) || trim((string) ($row['url'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'platform' => in_array($row['platform'] ?? null, self::LINK_PLATFORMS, true) ? $row['platform'] : 'custom',
                'label' => trim((string) ($row['label'] ?? '')),
                'url' => trim((string) $row['url']),
                'active' => filter_var($row['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $out;
    }
}

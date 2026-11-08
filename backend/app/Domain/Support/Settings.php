<?php

namespace App\Domain\Support;

use App\Domain\Support\Models\Setting;
use App\Support\MediaUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The one place that reads and writes store settings (table `settings`, key/value, cached).
 *
 * Every setting is declared in DEFINITIONS with its group, type, default and label, so a missing row falls back to the default,
 * the admin Settings page can be built from the list, and writes are validated by type. Client values (store name, currency, tax, …)
 * live here, not in code. *
 * Types: string | text | bool | int | money | percent | file | links | accounts | color | choice | secret
 *  - `secret` a value stored ENCRYPTED that no API ever returns (the admin API only says whether it is set); `get()` decrypts for server-side use
 *  - `file`  a picture owned by the setting's row (medialibrary); the value is the media id, uploaded through the admin endpoints
 *  - `links` a JSON list of {platform, label, url, active}; the order of the list is the display order
 *  - `accounts` a JSON list of {bank, title, number, iban, active} (bank transfer accounts); the order of the list is the display order
 *  - `color` a #RRGGBB colour       `choice` one value of a fixed list (CHOICES)
 */
class Settings
{
    public const CACHE_KEY = 'settings.all';

    public const FILES_CACHE_KEY = 'settings.files';

    /** Platforms a footer / social link can have. `custom` is any other site. */
    public const LINK_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok', 'whatsapp', 'pinterest', 'website', 'custom'];

    /** The fonts a store may pick for the storefront (a fixed list: the storefront loads them, nothing else). */
    public const FONTS = ['Inter', 'Poppins', 'Playfair Display', 'Plus Jakarta Sans', 'Roboto', 'Lato', 'system-ui'];

    public const DELIVERY_METHODS = ['flat', 'free', 'free_above', 'zones', 'owner_confirms'];

    /** The values of every `choice` setting. */
    public const CHOICES = [
        'delivery_method' => self::DELIVERY_METHODS,
        'delivery_unmatched' => ['block', 'owner_confirms', 'flat'],
        'theme_font_heading' => self::FONTS,
        'theme_font_body' => self::FONTS,
        'theme_radius' => ['0px', '4px', '8px', '12px', '16px', '999px'],
        'theme_shadow' => ['none', 'soft', 'medium'],
    ];

    /**
     * Who may see and change a setting, on top of the permission of its tab: `client` (the admin may get it by default) or `advanced` (technical:
     * the admin needs the permission `settings.advanced`, which only the developer has by default). Everything is `client` unless listed here
     * or in ADVANCED_GROUPS. docs/ACCESS_CONTROL.md has the full table (a test keeps it complete).
     */
    public const ADVANCED_KEYS = [
        'store_name', 'store_tagline', 'store_country', 'default_phone_country_code',
        'delivery_days', 'delivery_unmatched',
        'mail_from_name', 'mail_reply_to', 'notify_admin_email', 'storefront_track_url',
        'rebuild_hook_url', 'rebuild_hook_secret', 'rebuild_debounce_seconds',
    ];

    /** Every setting of these groups is `advanced` (branding, footer and agency credit, SEO, currency, tax, order numbers and the cash limit, theme). */
    public const ADVANCED_GROUPS = ['branding', 'footer', 'seo', 'currency', 'tax', 'orders', 'theme'];

    public const CLIENT = 'client';

    public const ADVANCED = 'advanced';

    /** The notification events and the three switches each has (null = that channel does not exist for the event). key => [label, customer e-mail, owner e-mail, admin push] */
    public const EVENTS = [
        'placed' => ['Order placed', 'mail_on_placed', 'owner_mail_on_placed', 'push_on_placed'],
        'confirmed' => ['Order confirmed', 'mail_on_confirmed', null, null],
        'shipped' => ['Order shipped', 'mail_on_shipped', null, null],
        'delivered' => ['Order delivered', 'mail_on_delivered', null, null],
        'cancelled' => ['Order cancelled or rejected', 'mail_on_cancelled', null, null],
        'proof_uploaded' => ['Payment proof uploaded', null, 'owner_mail_on_proof', 'push_on_proof'],
        'proof_rejected' => ['Payment proof rejected', 'mail_on_payment_proof_rejected', null, null],
        'fee_confirmed' => ['Delivery fee confirmed', 'mail_on_fee_confirmed', null, null],
        'wholesale' => ['New wholesale request', null, 'owner_mail_on_wholesale', 'push_on_wholesale'],
    ];

    /** key => [group, type, default, label, help?, min?, max?] */
    public const DEFINITIONS = [
        // --- store profile
        'store_name' => ['general', 'string', 'JHK Store', 'Store name'],
        'store_tagline' => ['general', 'string', '', 'Tagline'],
        'store_email' => ['general', 'string', '', 'Contact email'],
        'store_phone' => ['general', 'string', '', 'Contact phone'],
        'store_address' => ['general', 'text', '', 'Address'],
        'store_country' => ['general', 'string', '', 'Country', 'Used as the default country of addresses.'],
        'default_phone_country_code' => ['general', 'string', '', 'Default phone country code', 'e.g. +92. A phone typed with a single leading 0 and no country code gets it (0300… and +92300… are one customer). Empty = numbers are kept as typed.'],
        'support_hours' => ['general', 'string', '', 'Support hours', 'e.g. Mon-Fri 9:00-18:00.'],
        'whatsapp_number' => ['general', 'string', '', 'WhatsApp number', 'With the country code, e.g. +49 170 1234567.'],

        // --- branding (pictures are uploaded, not typed)
        'store_logo' => ['branding', 'file', '', 'Logo', 'PNG, JPG or WEBP, up to 2 MB.'],
        'store_favicon' => ['branding', 'file', '', 'Favicon', 'PNG, ICO, JPG or WEBP, up to 512 KB. A square picture works best.'],

        // --- footer and social links
        'social_links' => ['footer', 'links', [], 'Social and footer links', 'Any number of links, shown in this order.'],
        'footer_text' => ['footer', 'text', '', 'Footer text', 'A short text for the footer, e.g. what the store sells.'],
        'footer_copyright' => ['footer', 'string', '', 'Copyright text', 'e.g. © 2026 JHK Store. All rights reserved.'],
        'agency_name' => ['footer', 'string', 'Rivoxa Tech', 'Agency name', 'Shown as "Developed by" at the very bottom of the storefront and in the admin panel. Empty = no credit.'],
        'agency_url' => ['footer', 'string', 'https://rivoxa.tech', 'Agency link', 'The agency website (https:// only).'],
        'agency_logo' => ['footer', 'file', '', 'Agency logo', 'PNG, JPG or WEBP, up to 1 MB. Shown next to "Developed by" (the name is shown when there is no logo).'],

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
        'tax_label' => ['tax', 'string', 'Tax', 'Tax label', 'Shown to customers, e.g. VAT, Sales tax.'],

        // --- orders and delivery
        'order_number_prefix' => ['orders', 'string', 'ORD', 'Order number prefix', 'Order numbers look like PREFIX-2026-000123.'],
        'cod_max_amount' => ['orders', 'money', 0, 'Cash on delivery limit', '0 = no limit.'],

        // --- store: open / closed and notices
        'store_open' => ['store', 'bool', true, 'Store is open', 'Off: customers can still browse and see prices, but cannot place orders.'],
        'store_closed_message' => ['store', 'text', '', 'Message while the store is closed', 'Shown to customers instead of the checkout, e.g. We are closed until Monday.'],
        'checkout_notice' => ['store', 'text', '', 'Checkout notice', 'A short notice shown at checkout (up to 300 characters).'],

        // --- delivery (the zones themselves are managed in the Delivery tab, table delivery_zones)
        'delivery_method' => ['delivery', 'choice', 'free_above', 'Delivery method', 'Flat fee, always free, free above an amount, fee by delivery zone, or the owner confirms the fee after the order.'],
        'delivery_fee' => ['delivery', 'money', 0, 'Delivery charge', 'The fee of the flat and free-above methods (and of unmatched addresses set to flat). 0 = free delivery.'],
        'free_delivery_min_cart' => ['delivery', 'money', 0, 'Free delivery from', 'Cart value from which delivery is free (free-above method). 0 = never free.'],
        'delivery_days' => ['delivery', 'int', 5, 'Delivery time (days)', 'Shown to customers as the delivery estimate.', 0, 60],
        'delivery_unmatched' => ['delivery', 'choice', 'block', 'Address outside every zone', 'Zones method: refuse the order, let the owner confirm the fee, or use the flat delivery charge.'],
        'min_order_amount' => ['delivery', 'money', 0, 'Minimum order amount', '0 = no minimum.'],
        'delivery_eta_text' => ['delivery', 'string', '', 'Delivery estimate text', 'e.g. 2 to 4 days. Shown when a delivery zone has no estimate of its own.'],

        // --- payments
        'pay_cod_enabled' => ['payments', 'bool', true, 'Cash on delivery', 'Customers can pay the courier when the order arrives.'],
        'pay_bank_enabled' => ['payments', 'bool', false, 'Bank transfer', 'Customers pay into one of the accounts below and upload a screenshot of the transfer.'],
        'pay_card_visible' => ['payments', 'bool', false, 'Show "Card payment, coming soon"', 'Only a label: card payments are not available and can never be chosen.'],
        'pay_bank_instructions' => ['payments', 'text', '', 'Bank transfer instructions', 'Shown to the customer next to the accounts, e.g. Use your order number as the reference.'],
        'pay_bank_accounts' => ['payments', 'accounts', [], 'Bank accounts', 'Any number of accounts, shown in this order.'],

        // --- notifications: e-mails to customers and the owner (queued, see App\Mail), and the admin push switch
        'mail_enabled' => ['notifications', 'bool', false, 'Send e-mails', 'Master switch. Off: no e-mail is sent. The mail server (MAIL_* in .env) and the queue worker must be set up first, see docs/NEW_CLIENT.md.'],
        'mail_from_name' => ['notifications', 'string', '', 'Sender name', 'The name customers see on the e-mail. Empty = the store name.'],
        'mail_reply_to' => ['notifications', 'string', '', 'Reply-to address', 'Where a customer\'s reply goes. Empty = the store e-mail.'],
        'notify_admin_email' => ['notifications', 'string', '', 'Owner e-mail for alerts', 'Gets an alert for every new order, payment proof and wholesale request. Empty = no alerts.'],
        'mail_on_placed' => ['notifications', 'bool', true, 'E-mail: order received', 'Sent to the customer when the order is placed.'],
        'mail_on_confirmed' => ['notifications', 'bool', true, 'E-mail: order confirmed'],
        'mail_on_shipped' => ['notifications', 'bool', true, 'E-mail: order shipped', 'With the courier and tracking number.'],
        'mail_on_delivered' => ['notifications', 'bool', true, 'E-mail: order delivered'],
        'mail_on_cancelled' => ['notifications', 'bool', true, 'E-mail: order cancelled or rejected', 'With the reason.'],
        'mail_on_payment_proof_rejected' => ['notifications', 'bool', true, 'E-mail: payment proof rejected', 'Asks the customer to upload a new screenshot.'],
        'mail_on_fee_confirmed' => ['notifications', 'bool', true, 'E-mail: delivery fee confirmed', 'Sent to the customer when the owner has set the delivery fee of the order.'],
        'owner_mail_on_placed' => ['notifications', 'bool', true, 'Owner e-mail: new order', 'Needs the owner e-mail address.'],
        'owner_mail_on_proof' => ['notifications', 'bool', true, 'Owner e-mail: payment proof uploaded', 'Needs the owner e-mail address.'],
        'owner_mail_on_wholesale' => ['notifications', 'bool', true, 'Owner e-mail: new wholesale request', 'Needs the owner e-mail address.'],
        'push_on_placed' => ['notifications', 'bool', true, 'Push: new order'],
        'push_on_proof' => ['notifications', 'bool', true, 'Push: payment proof uploaded'],
        'push_on_wholesale' => ['notifications', 'bool', true, 'Push: new wholesale request'],
        'storefront_track_url' => ['notifications', 'string', '', 'Order tracking page', 'The storefront page that tracks an order, e.g. https://shop.example/track. E-mails link to it as ?order=NUMBER (the customer is asked for the phone number).'],
        'push_enabled' => ['notifications', 'bool', false, 'Admin push notifications', 'Browser notifications for new orders, payment proofs and wholesale requests (needs the VAPID keys in .env: php artisan shop:vapid).'],

        // --- theme (developer only): the storefront reads these through GET /store/settings
        'theme_primary' => ['theme', 'color', '#2563EB', 'Primary colour', 'Buttons and links.'],
        'theme_primary_contrast' => ['theme', 'color', '#FFFFFF', 'Text on the primary colour'],
        'theme_accent' => ['theme', 'color', '#F59E0B', 'Accent colour', 'Badges and highlights.'],
        'theme_bg' => ['theme', 'color', '#FFFFFF', 'Page background'],
        'theme_surface' => ['theme', 'color', '#F8FAFC', 'Card background'],
        'theme_text' => ['theme', 'color', '#0F172A', 'Text colour'],
        'theme_muted' => ['theme', 'color', '#64748B', 'Muted text colour'],
        'theme_border' => ['theme', 'color', '#E2E8F0', 'Border colour'],
        'theme_font_heading' => ['theme', 'choice', 'Inter', 'Heading font'],
        'theme_font_body' => ['theme', 'choice', 'Inter', 'Body font'],
        'theme_radius' => ['theme', 'choice', '8px', 'Corner radius'],
        'theme_shadow' => ['theme', 'choice', 'soft', 'Shadow'],

        // --- website: the rebuild of the static storefront (docs/NEW_CLIENT.md). Not public: the storefront never sees these.
        'rebuild_enabled' => ['website', 'bool', false, 'Rebuild the website automatically', 'On: a change that customers can see (products, categories, banners, theme, ...) asks the hosting to rebuild the website, about two minutes after the last change.'],
        'rebuild_hook_url' => ['website', 'string', '', 'Rebuild hook address', 'The deploy hook of the hosting (https only), e.g. the Cloudflare Pages deploy hook. It is called with an empty POST.'],
        'rebuild_hook_secret' => ['website', 'secret', '', 'Rebuild hook secret', 'Optional. Sent as the header X-Rebuild-Secret. Stored encrypted; it is never shown again, only whether it is set.'],
        'rebuild_debounce_seconds' => ['website', 'int', 120, 'Wait after the last change (seconds)', 'Edits made within this time cause a single rebuild.', 10, 3600],

    ];

    /** @var array<string,mixed>|null per-request copy of the stored values */
    private ?array $stored = null;

    /** @var array<int,string>|null media id => absolute URL of every `file` setting picture */
    private ?array $fileUrls = null;

    /** `client` or `advanced`: who may see and change the setting besides the permission of its tab. */
    public static function access(string $key): string
    {
        $def = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting [$key].");

        return in_array($key, self::ADVANCED_KEYS, true) || in_array($def[0], self::ADVANCED_GROUPS, true) ? self::ADVANCED : self::CLIENT;
    }

    /** Typed value of a setting (the stored one, or the declared default). */
    public function get(string $key): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting [$key].");
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $this->cast($def[1], $stored[$key]) : $def[2];
    }

    /** Is a `secret` setting set? (the only thing an API may say about it) */
    public function isSet(string $key): bool
    {
        return (string) $this->get($key) !== '';
    }

    /** @return array<string,mixed> every setting except the secrets, typed, by key */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if ($def[1] !== 'secret') {   // a secret is never part of a list: ask for it with get() where it is used, or isSet() to know whether it exists
                $out[$key] = $this->get($key);
            }
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
            Setting::query()->updateOrCreate(['key' => $key], ['group_key' => $group, 'value' => $type === 'secret' ? $this->encryptSecret($value) : $this->cast($type, $value)]);
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
        Cache::forget(self::filesCacheKey());
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
            if (self::DEFINITIONS[$key][1] === 'accounts') {
                $out["$key.*.bank"] = 'bank name';
                $out["$key.*.title"] = 'account title';
                $out["$key.*.number"] = 'account number';
                $out["$key.*.iban"] = 'IBAN';
            }
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
            if ($key === 'default_phone_country_code') {
                $out["$key.regex"] = 'Enter the country code with a + and 1 to 4 digits, for example +92.';
            }
            if (self::DEFINITIONS[$key][1] === 'color') {
                $out["$key.regex"] = 'Enter a colour as #RRGGBB, for example #2563EB.';
            }
            if ($key === 'storefront_track_url') {
                $out["$key.url"] = 'Enter a full web address starting with https://.';
            }
            if ($key === 'rebuild_hook_url') {
                $out["$key.url"] = 'Enter a full web address starting with https://.';
            }
            if ($key === 'agency_url') {
                $out["$key.url"] = 'Enter a full web address starting with https://.';
            }
            if (self::DEFINITIONS[$key][1] === 'accounts') {
                $out["$key.*.bank.required"] = 'Enter the bank name.';
                $out["$key.*.number.required_without"] = 'Enter the account number or the IBAN.';
                $out["$key.*.iban.required_without"] = 'Enter the account number or the IBAN.';
            }
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
                'links', 'accounts' => ['present', 'array'],
                'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secret' => ['nullable', 'string', 'max:200'],
                'choice' => ['required', 'string', 'in:'.implode(',', self::CHOICES[$key])],
                default => ['nullable', 'string', 'max:255'],
            };
            if ($def[1] === 'accounts') {
                $rules["$key.*.bank"] = ['required', 'string', 'max:80'];
                $rules["$key.*.title"] = ['nullable', 'string', 'max:120'];
                $rules["$key.*.number"] = ['nullable', 'required_without:'.$key.'.*.iban', 'string', 'max:40'];
                $rules["$key.*.iban"] = ['nullable', 'required_without:'.$key.'.*.number', 'string', 'max:40'];
                $rules["$key.*.active"] = ['nullable', 'boolean'];
            }
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
            'default_phone_country_code' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{0,3}$/'],
            'whatsapp_number' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9][0-9 ()-]{4,23}$/'],
            'seo_meta_title' => ['nullable', 'string', 'max:70'],
            'seo_meta_description' => ['nullable', 'string', 'max:300'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'agency_name' => ['nullable', 'string', 'max:80'],
            'agency_url' => ['nullable', 'url:https', 'max:255'],
            'store_closed_message' => ['nullable', 'string', 'max:300'],
            'checkout_notice' => ['nullable', 'string', 'max:300'],
            'delivery_eta_text' => ['nullable', 'string', 'max:120'],
            'pay_bank_instructions' => ['nullable', 'string', 'max:1000'],
            'mail_from_name' => ['nullable', 'string', 'max:80'],
            'mail_reply_to' => ['nullable', 'email', 'max:190'],
            'notify_admin_email' => ['nullable', 'email', 'max:190'],
            'storefront_track_url' => ['nullable', 'url:http,https', 'max:255'],
            'rebuild_hook_url' => ['nullable', 'url:https', 'max:500'],
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

    public function deliveryMethod(): string
    {
        return (string) $this->get('delivery_method');
    }

    /** The accounts a customer may pay into: the active ones, in order. */
    public function publicAccounts(): array
    {
        return collect($this->get('pay_bank_accounts'))->where('active', true)->map(fn ($a) => [
            'bank' => $a['bank'], 'title' => $a['title'], 'number' => $a['number'], 'iban' => $a['iban'],
        ])->values()->all();
    }

    public function minOrderCents(): int
    {
        return $this->cents($this->get('min_order_amount'));
    }

    public function codMaxCents(): int
    {
        return $this->cents($this->get('cod_max_amount'));
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

    /**
     * The picture URLs are absolute, so the cache entry belongs to the address they were built with: a changed APP_URL (or a picture
     * disk URL) must not keep serving links to the old host (a logo that is uploaded but never shows). Only configuration goes into the key,
     * never the host of the current request: an upload through one address must clear the entry that every other address reads.
     */
    private static function filesCacheKey(): string
    {
        return self::FILES_CACHE_KEY.'.'.md5(config('app.url').'|'.config('filesystems.disks.public.url'));
    }

    /** @return array<int,string> */
    private function fileUrls(): array
    {
        return $this->fileUrls ??= Cache::rememberForever(self::filesCacheKey(), fn () => Media::query()
            ->where('model_type', (new Setting)->getMorphClass())->get()
            ->mapWithKeys(fn (Media $m) => [$m->id => MediaUrl::media($m)])->all());
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'links' => $this->castLinks($value),
            'secret' => $this->castSecret($value),
            'accounts' => $this->accountsFrom($value),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'money', 'percent' => round((float) $value, 2),
            default => $value === null ? '' : (string) $value,
        };
    }

    private function encryptSecret(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : Crypt::encryptString($value);
    }

    /** Decrypts a stored secret; a value that cannot be decrypted (the APP_KEY changed) counts as not set. */
    private function castSecret(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return '';
        }
    }

    /** Keeps only well-formed rows (a bank name and an account number or an IBAN), in the given order. */
    public function accountsFrom(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $row) {
            $row = is_array($row) ? $row : [];
            $bank = trim((string) ($row['bank'] ?? ''));
            $number = trim((string) ($row['number'] ?? ''));
            $iban = trim((string) ($row['iban'] ?? ''));
            if ($bank === '' || ($number === '' && $iban === '')) {
                continue;
            }
            $out[] = [
                'bank' => $bank, 'title' => trim((string) ($row['title'] ?? '')), 'number' => $number, 'iban' => $iban,
                'active' => filter_var($row['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $out;
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

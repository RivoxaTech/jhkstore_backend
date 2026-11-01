<?php

namespace App\Domain\Support;

use App\Domain\Support\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The one place that reads and writes store settings (table `settings`, key/value, cached).
 *
 * Every setting is declared in DEFINITIONS with its group, type, default and label, so a missing row falls back to the default,
 * the admin Settings page can be built from the list, and writes are validated by type. Client values (store name, currency, tax, …)
 * live here, not in code. Feature switches (`rewards_enabled`) are read through the helpers at the bottom,
 * so every module checks them in the same way.
 *
 * Types: string | text | bool | int | money | percent | url
 */
class Settings
{
    public const CACHE_KEY = 'settings.all';

    /** key => [group, type, default, label, help?, min?, max?] */
    public const DEFINITIONS = [
        // --- store profile
        'store_name' => ['general', 'string', 'My Store', 'Store name'],
        'store_tagline' => ['general', 'string', '', 'Tagline'],
        'store_logo' => ['general', 'file', '', 'Logo', 'Upload from the Settings page (PNG, JPG or WEBP).'],
        'store_email' => ['general', 'string', '', 'Contact email'],
        'store_phone' => ['general', 'string', '', 'Contact phone'],
        'store_address' => ['general', 'text', '', 'Address'],
        'store_country' => ['general', 'string', '', 'Country', 'Used as the default country of addresses.'],

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
        Cache::forget(self::CACHE_KEY);
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
        $path = (string) $this->get('store_logo');

        return $path === '' ? null : url(Storage::disk('public')->url($path));
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

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'money', 'percent' => round((float) $value, 2),
            default => $value === null ? '' : (string) $value,
        };
    }
}

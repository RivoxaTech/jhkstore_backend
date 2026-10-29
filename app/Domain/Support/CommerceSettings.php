<?php

namespace App\Domain\Support;

use App\Domain\Support\Models\SiteSetting;

/**
 * The shop rules an admin can change in Settings (all live in `site_settings`, nothing is hard-coded):
 * delivery charge, free-delivery limit (0 = never free), coin value (money per coin), COD limit, minimum order amount (0 = none)
 * and the coin redemption caps.
 */
class CommerceSettings
{
    /** key => [group, default, kind]; the defaults are the seeded ones. */
    public const DEFINITIONS = [
        'delivery_fee' => ['shipping', 300, 'money'],
        'free_delivery_min_cart' => ['shipping', 8500, 'money'],
        'coin_value' => ['rewards', 1, 'money'],
        'max_redeem_coins_per_order' => ['rewards', 5000, 'int'],
        'redeem_cap_order_percent' => ['rewards', 100, 'percent'],
        'redeem_cap_balance_percent' => ['rewards', 100, 'percent'],
        'cod_max_amount' => ['orders', 150000, 'money'],
        'min_order_amount' => ['orders', 0, 'money'],
    ];

    public function get(string $key): float|int
    {
        [, $default, $kind] = self::DEFINITIONS[$key];
        $value = SiteSetting::read($key, $default);

        return $kind === 'int' ? (int) $value : (float) $value;
    }

    /** @return array<string, float|int> every setting above, by key */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** @param  array<string, numeric>  $values */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }
            [$group, , $kind] = self::DEFINITIONS[$key];
            SiteSetting::write($group, $key, $kind === 'int' ? (int) $value : round((float) $value, 2));
        }
    }

    public function deliveryFeeCents(): int
    {
        return self::cents($this->get('delivery_fee'));
    }

    public function freeDeliveryMinCents(): int
    {
        return self::cents($this->get('free_delivery_min_cart'));
    }

    public function coinValueCents(): int
    {
        return max(1, self::cents($this->get('coin_value')));
    }

    public function minOrderCents(): int
    {
        return self::cents($this->get('min_order_amount'));
    }

    public function codMaxCents(): int
    {
        return self::cents($this->get('cod_max_amount'));
    }

    public function rewardExpiryDays(): int
    {
        return max(1, (int) SiteSetting::read('reward_expiry_days', 365));
    }

    public function currencyCode(): string
    {
        return (string) SiteSetting::read('currency_code', 'PKR');
    }

    public function currencySymbol(): string
    {
        return (string) SiteSetting::read('currency_symbol', 'Rs');
    }

    /** Money is handled in whole cents inside the cart maths, so there are no floating point surprises. */
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}

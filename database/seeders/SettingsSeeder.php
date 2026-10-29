<?php

namespace Database\Seeders;

use App\Domain\Support\Models\SiteSetting;
use Illuminate\Database\Seeder;

/** Defaults taken from the live site; every value is editable from the admin panel. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Same delivery charge for every pincode and both channels; free above a cart value.
        $defaults = [
            ['shipping', 'delivery_fee', 300],
            ['shipping', 'free_delivery_min_cart', 8500],
            ['rewards', 'coin_value', 1],   // 1 coin = Rs 1 (editable in Settings)
            ['rewards', 'reward_expiry_days', 365],
            ['rewards', 'max_redeem_coins_per_order', 5000],
            ['rewards', 'redeem_cap_order_percent', 100],     // % of the order that coins may pay (100 = no extra cap)
            ['rewards', 'redeem_cap_balance_percent', 100],   // % of the wallet one order may use (100 = no extra cap)
            ['orders', 'cod_max_amount', 150000],
            ['orders', 'min_order_amount', 0],                // 0 = no minimum order
            ['orders', 'return_window_days', 10],
            ['general', 'currency_code', 'PKR'],
            ['general', 'currency_symbol', 'Rs'],
        ];

        foreach ($defaults as [$group, $key, $value]) {
            if (! SiteSetting::where('key', $key)->exists()) {
                SiteSetting::write($group, $key, $value);
            }
        }
    }
}

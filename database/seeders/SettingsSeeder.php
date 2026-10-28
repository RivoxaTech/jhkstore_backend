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
            ['rewards', 'coin_value', 1.5],
            ['rewards', 'reward_expiry_days', 365],
            ['orders', 'cod_max_amount', 150000],
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

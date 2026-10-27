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
            ['shipping', 'delivery_fee', 95],
            ['shipping', 'free_delivery_min_cart', 2800],
            ['rewards', 'coin_value', 0.5],
            ['rewards', 'reward_expiry_days', 365],
            ['orders', 'cod_max_amount', 49000],
            ['orders', 'return_window_days', 10],
        ];

        foreach ($defaults as [$group, $key, $value]) {
            if (! SiteSetting::where('key', $key)->exists()) {
                SiteSetting::write($group, $key, $value);
            }
        }
    }
}

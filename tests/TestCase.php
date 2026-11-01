<?php

namespace Tests;

use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the base data every API test depends on: the default settings plus a fixed test profile
     * (so the tests do not depend on the neutral defaults a new client starts with).
     */
    protected function seedBasics(): void
    {
        $this->seed(SettingsSeeder::class);

        app(Settings::class)->set([
            'currency_code' => 'PKR', 'currency_symbol' => 'Rs', 'order_number_prefix' => 'DK',
            'delivery_fee' => 300, 'free_delivery_min_cart' => 8500, 'delivery_days' => 5, 'cod_max_amount' => 150000,
            'brands_enabled' => true, 'rewards_enabled' => true,
        ]);
        app(Settings::class)->all();   // warm the per-request copy so query-count tests do not see the one-off load
    }

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::create(['username' => 'tester', 'name' => 'Tester', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }
}

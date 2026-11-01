<?php

namespace Tests;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

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
            'rewards_enabled' => true, 'tax_rate' => 18, 'tax_inclusive' => true, 'store_country' => 'Pakistan',
        ]);
        app(Settings::class)->all();   // warm the per-request copy so query-count tests do not see the one-off load
    }

    /** An admin with the given role (the roles and permissions are written first). */
    protected function makeAdmin(string $role = AdminAccess::SUPER_ADMIN, string $username = 'tester'): Admin
    {
        AdminAccess::sync();
        $admin = Admin::query()->where('username', $username)->first()
            ?? Admin::create(['username' => $username, 'name' => ucfirst($username), 'password' => 'secret-pass', 'is_active' => true]);
        $admin->syncRoles($role);

        return $admin;
    }

    /** Makes an admin (a super admin unless another role is given) the caller of the following requests. */
    protected function actingAsAdmin(string $role = AdminAccess::SUPER_ADMIN): Admin
    {
        $this->app['auth']->forgetGuards();
        $admin = $this->makeAdmin($role);
        $admin->getAllPermissions();   // load the roles and permissions now, so query-count tests do not see the one-off load
        app(PermissionRegistrar::class)->getPermissions();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }
}

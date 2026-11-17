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
            'currency_code' => 'USD', 'currency_symbol' => '$', 'order_number_prefix' => 'ORD',
            'delivery_fee' => 300, 'free_delivery_min_cart' => 8500, 'delivery_days' => 5, 'cod_max_amount' => 150000,
            'tax_rate' => 18, 'tax_inclusive' => true, 'tax_label' => 'VAT', 'store_country' => 'Testland',
        ]);
        app(Settings::class)->all();   // warm the per-request copy so query-count tests do not see the one-off load
    }

    /** An admin account with the given role (the roles and permissions are written first). */
    protected function makeAdmin(string $role = AdminAccess::ADMIN, string $username = 'tester'): Admin
    {
        AdminAccess::sync();
        $admin = Admin::query()->where('username', $username)->first()
            ?? Admin::create(['username' => $username, 'name' => ucfirst($username), 'password' => 'secret-pass', 'is_active' => true]);
        $admin->syncRoles($role);

        return $admin;
    }

    /**
     * Makes an admin the caller of the following requests. The default is the `admin` role with EVERY switchable permission on
     * (what a developer would get by switching everything on); pass `$everything = false` for the real defaults of the role.
     * The `developer` role always has its own fixed access, whatever `$everything` says.
     */
    protected function actingAsAdmin(string $role = AdminAccess::ADMIN, bool $everything = true): Admin
    {
        $this->app['auth']->forgetGuards();
        $admin = $this->makeAdmin($role);
        if ($role === AdminAccess::ADMIN && $everything) {
            AdminAccess::setRolePermissions(AdminAccess::ADMIN, AdminAccess::togglable(AdminAccess::ADMIN));
        }
        $admin->unsetRelation('roles')->unsetRelation('permissions');
        AdminAccess::effective($admin);   // load the roles and permissions now, so query-count tests do not see the one-off load
        app(PermissionRegistrar::class)->getPermissions();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }
}

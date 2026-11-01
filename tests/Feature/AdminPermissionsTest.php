<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    /** Routes any logged-in admin may call (they only describe the caller). */
    private const OPEN = ['auth/logout', 'auth/me', 'auth/login'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    /** One representative call per permission: [permission, method, uri, body]. Ids that do not exist answer 404, which still proves the permission passed. */
    private function endpoints(): array
    {
        return [
            ['dashboard.view', 'GET', '/dashboard', []],
            ['catalog.view', 'GET', '/categories', []],
            ['catalog.view', 'GET', '/categories/options', []],
            ['catalog.view', 'GET', '/products', []],
            ['catalog.view', 'GET', '/products/options', []],
            ['catalog.view', 'GET', '/products/999999', []],
            ['catalog.view', 'GET', '/tags', []],
            ['catalog.manage', 'POST', '/categories', []],
            ['catalog.manage', 'PUT', '/categories/999999', []],
            ['catalog.manage', 'DELETE', '/categories/999999', []],
            ['catalog.manage', 'POST', '/categories/999999/sub-categories', []],
            ['catalog.manage', 'DELETE', '/sub-categories/999999', []],
            ['catalog.manage', 'POST', '/products', []],
            ['catalog.manage', 'PUT', '/products/999999', []],
            ['catalog.manage', 'DELETE', '/products/999999', []],
            ['catalog.manage', 'PATCH', '/products/999999/status', []],
            ['catalog.manage', 'PUT', '/products/999999/tags', []],
            ['catalog.manage', 'POST', '/products/999999/images', []],
            ['catalog.manage', 'PUT', '/products/999999/images/order', []],
            ['catalog.manage', 'PUT', '/products/999999/images/1/primary', []],
            ['catalog.manage', 'DELETE', '/products/999999/images/1', []],
            ['catalog.manage', 'POST', '/tags', []],
            ['catalog.manage', 'DELETE', '/tags/999999', []],
            ['banners.view', 'GET', '/banners', []],
            ['banners.view', 'GET', '/banners/options', []],
            ['banners.manage', 'POST', '/banners', []],
            ['banners.manage', 'PUT', '/banners/reorder', []],
            ['banners.manage', 'DELETE', '/banners/999999', []],
            ['orders.view', 'GET', '/orders', []],
            ['orders.view', 'GET', '/orders/options', []],
            ['orders.view', 'GET', '/orders/999999', []],
            ['orders.manage', 'POST', '/orders/999999/status', []],
            ['orders.manage', 'PATCH', '/orders/999999/note', []],
            ['settings.view', 'GET', '/settings', []],
            ['settings.manage', 'PUT', '/settings', ['store_name' => 'Allowed']],
            ['settings.manage', 'POST', '/settings/logo', []],
            ['settings.manage', 'DELETE', '/settings/favicon', []],
            ['admins.manage', 'GET', '/users', []],
            ['admins.manage', 'GET', '/roles', []],
            ['admins.manage', 'POST', '/users', []],
            ['admins.manage', 'DELETE', '/users/999999', []],
        ];
    }

    public function test_every_admin_route_needs_a_login_and_a_permission(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1/admin/'));
            if (in_array($path, self::OPEN, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('actor:admin', $middleware, "admin route $path has no admin login check");
            $this->assertTrue(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'admin.can:')), "admin route {$route->methods()[0]} $path has no permission check");
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);

        // every permission that routes ask for exists in the matrix, and every permission of the matrix is used by a route
        $used = collect(Route::getRoutes()->getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'admin.can:'))->map(fn ($m) => substr($m, 10))->unique()->sort()->values()->all();
        $this->assertSame(collect(array_keys(AdminAccess::PERMISSIONS))->sort()->values()->all(), $used);
    }

    public function test_each_role_can_call_exactly_what_the_matrix_gives_it(): void
    {
        foreach (array_keys(AdminAccess::ROLES) as $role) {
            $this->actingAsAdmin($role);
            $allowed = $role === AdminAccess::SUPER_ADMIN ? array_keys(AdminAccess::PERMISSIONS) : AdminAccess::ROLES[$role][2];

            foreach ($this->endpoints() as [$permission, $method, $uri, $body]) {
                $res = $this->json($method, self::ADMIN.$uri, $body);
                if (in_array($permission, $allowed, true)) {
                    $this->assertNotContains($res->status(), [401, 403], "$role should be allowed: $method $uri ($permission) got {$res->status()}");
                } else {
                    $res->assertForbidden()->assertJsonPath('message', 'You do not have permission to do this.');
                }
            }
        }
    }

    public function test_the_roles_are_what_the_docs_promise(): void
    {
        $this->assertSame(['super_admin', 'manager', 'staff'], array_keys(AdminAccess::ROLES));

        $manager = AdminAccess::ROLES['manager'][2];
        $staff = AdminAccess::ROLES['staff'][2];
        $this->assertContains('catalog.manage', $manager);
        $this->assertNotContains('settings.manage', $manager);
        $this->assertNotContains('admins.manage', $manager);
        $this->assertNotContains('catalog.manage', $staff);
        $this->assertNotContains('banners.manage', $staff);
        $this->assertContains('orders.manage', $staff);
        $this->assertSame([], array_diff([...$manager, ...$staff], array_keys(AdminAccess::PERMISSIONS)));   // no typos
    }

    public function test_a_super_admin_has_every_permission_and_me_lists_them(): void
    {
        $this->actingAsAdmin();
        $res = $this->getJson(self::ADMIN.'/auth/me')->assertOk()->assertJsonPath('admin.roles.0', 'super_admin');
        $this->assertSame(collect(array_keys(AdminAccess::PERMISSIONS))->sort()->values()->all(), $res->json('admin.permissions'));

        $this->actingAsAdmin('staff');
        $this->assertSame(collect(AdminAccess::ROLES['staff'][2])->sort()->values()->all(), $this->getJson(self::ADMIN.'/auth/me')->json('admin.permissions'));
    }

    public function test_an_admin_without_a_role_can_log_in_but_do_nothing(): void
    {
        $admin = Admin::create(['username' => 'norole', 'name' => 'No Role', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson(self::ADMIN.'/auth/me')->assertOk()->assertJsonPath('admin.permissions', []);
        $this->getJson(self::ADMIN.'/dashboard')->assertForbidden();
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
        $this->getJson(self::ADMIN.'/settings')->assertForbidden();
    }

    public function test_the_permission_is_checked_before_the_record_is_looked_up(): void
    {
        $this->actingAsAdmin('staff');

        // a forbidden call is 403 whether or not the record exists: nothing leaks
        $this->putJson(self::ADMIN.'/products/999999', ['name' => 'x'])->assertForbidden();
        $this->deleteJson(self::ADMIN.'/banners/999999')->assertForbidden();
    }

    public function test_customers_and_guests_still_get_401_not_403(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN.'/products')->assertUnauthorized();
        $this->putJson(self::ADMIN.'/products/1', [])->assertUnauthorized();

        $customer = User::create(['full_name' => 'C', 'email' => 'c@example.test', 'mobile' => '03111111111', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($customer, ['*']);
        $this->getJson(self::ADMIN.'/dashboard')->assertUnauthorized();
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'x'])->assertUnauthorized();
    }

    public function test_a_deactivated_admin_loses_access_at_once(): void
    {
        $admin = $this->actingAsAdmin('manager');
        $this->getJson(self::ADMIN.'/products')->assertOk();

        $admin->forceFill(['is_active' => false])->save();
        $this->getJson(self::ADMIN.'/products')->assertUnauthorized();
    }

    public function test_changing_a_role_changes_access_immediately(): void
    {
        $admin = $this->actingAsAdmin('staff');
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Nope'])->assertForbidden();

        $admin->syncRoles('manager');
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Yes'])->assertCreated();
    }

    public function test_the_base_seeder_creates_the_roles_and_a_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['manager', 'staff', 'super_admin'], \Spatie\Permission\Models\Role::query()->where('guard_name', 'admin')->orderBy('name')->pluck('name')->all());
        $this->assertSame(count(AdminAccess::PERMISSIONS), \Spatie\Permission\Models\Permission::query()->where('guard_name', 'admin')->count());
        $admin = Admin::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertSame(count(AdminAccess::PERMISSIONS), $admin->getAllPermissions()->count());

        // seeding again resets the roles to the matrix (no duplicates)
        \Spatie\Permission\Models\Role::findByName('staff', 'admin')->givePermissionTo('settings.manage');
        $this->seed(DatabaseSeeder::class);
        $this->assertFalse(\Spatie\Permission\Models\Role::findByName('staff', 'admin')->hasPermissionTo('settings.manage'));
        $this->assertSame(3, \Spatie\Permission\Models\Role::query()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Order\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Two accounts (developer, admin), permissions per section and per Settings tab, controlled by the developer. */
class AccessControlTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    /** Routes any logged-in admin may call (they only describe the caller). */
    private const OPEN = ['auth/logout', 'auth/me', 'auth/login'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    /** One representative call per permission: [permission, method, uri, body]. Ids that do not exist answer 404, which still proves the permission passed. */
    private function endpoints(): array
    {
        return [
            ['dashboard.view', 'GET', '/dashboard', []],
            ['categories.view', 'GET', '/categories', []],
            ['categories.view', 'GET', '/categories/999999', []],
            ['categories.manage', 'POST', '/categories', []],
            ['categories.manage', 'PUT', '/categories/999999', []],
            ['categories.manage', 'DELETE', '/categories/999999', []],
            ['categories.manage', 'POST', '/categories/999999/sub-categories', []],
            ['categories.manage', 'DELETE', '/sub-categories/999999', []],
            ['products.view', 'GET', '/products', []],
            ['products.view', 'GET', '/products/options', []],
            ['products.view', 'GET', '/products/999999', []],
            ['products.view', 'GET', '/tags', []],
            ['products.manage', 'POST', '/products', []],
            ['products.manage', 'PUT', '/products/999999', []],
            ['products.manage', 'DELETE', '/products/999999', []],
            ['products.manage', 'PATCH', '/products/999999/status', []],
            ['products.manage', 'PUT', '/products/999999/tags', []],
            ['products.manage', 'POST', '/products/999999/images', []],
            ['products.manage', 'PUT', '/products/999999/images/order', []],
            ['products.manage', 'PUT', '/products/999999/images/1/primary', []],
            ['products.manage', 'DELETE', '/products/999999/images/1', []],
            ['products.manage', 'POST', '/tags', []],
            ['products.manage', 'DELETE', '/tags/999999', []],
            ['settings.banners', 'GET', '/banners', []],
            ['settings.banners', 'GET', '/banners/options', []],
            ['settings.banners', 'POST', '/banners', []],
            ['settings.banners', 'PUT', '/banners/reorder', []],
            ['settings.banners', 'DELETE', '/banners/999999', []],
            ['orders.view', 'GET', '/orders', []],
            ['orders.view', 'GET', '/orders/options', []],
            ['orders.view', 'GET', '/orders/999999', []],
            ['orders.manage', 'POST', '/orders/999999/status', []],
            ['orders.manage', 'PATCH', '/orders/999999/note', []],
            ['customers.view', 'GET', '/customers', []],
            ['customers.export', 'GET', '/customers/export', []],
            ['settings.general', 'GET', '/settings', []],
            ['settings.general', 'PUT', '/settings', ['store_name' => 'Allowed']],
            ['settings.branding', 'POST', '/settings/logo', []],
            ['settings.branding', 'DELETE', '/settings/favicon', []],
            ['settings.seo', 'POST', '/settings/share-image', []],
            ['access.manage', 'GET', '/access-control', []],
            ['access.manage', 'PUT', '/access-control/admin', ['permissions' => []]],
        ];
    }

    // ------------------------------------------------------------------ every route is guarded

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

        // every permission that routes ask for exists, and every permission of the list is used by a route
        $used = collect(Route::getRoutes()->getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'admin.can:'))->flatMap(fn ($m) => explode(',', substr($m, 10)))->unique()->sort()->values()->all();
        $this->assertSame(collect(AdminAccess::all())->sort()->values()->all(), $used);
    }

    public function test_the_permission_list_is_what_the_docs_promise(): void
    {
        $this->assertEqualsCanonicalizing([
            'dashboard.view', 'products.view', 'products.manage', 'categories.view', 'categories.manage', 'orders.view', 'orders.manage',
            'customers.view', 'customers.export', 'settings.general', 'settings.branding', 'settings.banners', 'settings.footer',
            'settings.currency_tax', 'settings.orders_delivery', 'settings.seo', 'access.manage',
        ], AdminAccess::all());
        $this->assertSame(['developer', 'admin'], AdminAccess::ROLES);
    }

    // ------------------------------------------------------------------ the seeded accounts

    public function test_the_base_seeder_creates_exactly_two_accounts_with_the_default_access(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Admin::count());
        $this->assertEqualsCanonicalizing(['developer', 'admin'], Role::query()->where('guard_name', 'admin')->pluck('name')->all());
        $this->assertSame(count(AdminAccess::all()), Permission::query()->where('guard_name', 'admin')->count());

        $developer = Admin::where('username', 'developer')->firstOrFail();
        $admin = Admin::where('username', 'admin')->firstOrFail();
        $this->assertTrue($developer->hasRole('developer'));
        $this->assertTrue($admin->hasRole('admin'));

        // the client starts with the dashboard, orders, customers and three Settings tabs
        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'orders.view', 'orders.manage', 'customers.view', 'customers.export', 'settings.general', 'settings.footer', 'settings.orders_delivery'],
            AdminAccess::effective($admin),
        );
        // the developer has everything except orders and (by default) customers
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(AdminAccess::all(), ['orders.view', 'orders.manage', 'customers.view', 'customers.export'])),
            AdminAccess::effective($developer),
        );
    }

    public function test_seeding_again_keeps_what_the_developer_switched(): void
    {
        $this->seed(DatabaseSeeder::class);
        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'products.view']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Admin::count());
        $this->assertEqualsCanonicalizing(['dashboard.view', 'products.view'], Role::findByName('admin', 'admin')->permissions->pluck('name')->all());
    }

    public function test_the_seeder_refuses_weak_passwords_in_production(): void
    {
        $this->app['env'] = 'production';
        foreach (['SEED_DEVELOPER_PASSWORD', 'SEED_ADMIN_PASSWORD'] as $weak) {
            putenv('SEED_DEVELOPER_PASSWORD=a-long-strong-password');
            putenv('SEED_ADMIN_PASSWORD=another-strong-password');
            putenv("$weak=short");
            try {
                (new \Database\Seeders\AdminSeeder)->run();
                $this->fail("a weak $weak must be refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('weak', $e->getMessage());
            } finally {
                putenv('SEED_DEVELOPER_PASSWORD');
                putenv('SEED_ADMIN_PASSWORD');
            }
        }
        $this->assertSame(0, Admin::count());
    }

    public function test_there_is_no_way_to_create_more_admins(): void
    {
        $this->actingAsAdmin('developer');

        foreach (['/users', '/roles'] as $uri) {
            $this->postJson(self::ADMIN.$uri, ['username' => 'third'])->assertStatus(404);
        }
        $this->assertSame(1, Admin::count());
    }

    // ------------------------------------------------------------------ the developer never sees orders

    public function test_the_developer_is_blocked_from_every_order_endpoint(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 0100']);
        $this->actingAsAdmin('developer');

        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
        $this->getJson(self::ADMIN.'/orders/options')->assertForbidden();
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertForbidden();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'x'])->assertForbidden();
        $this->getJson(self::ADMIN.'/orders/999999')->assertForbidden();   // 403 before the record is looked up: nothing leaks

        $this->assertSame('pending', Order::find($order->id)->status->value);
    }

    public function test_the_developer_cannot_give_themselves_order_permissions(): void
    {
        $order = $this->orderFor();
        $dev = $this->actingAsAdmin('developer');

        // through the Access Control API
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['orders.view', 'orders.manage']])->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['orders.view', 'customers.view']])->assertStatus(422);
        $this->assertFalse(Role::findByName('developer', 'admin')->hasPermissionTo('orders.view'));
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();

        // through the model
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            AdminAccess::setRolePermissions('developer', ['orders.manage']);
        } finally {
            // even a row written straight into the database is ignored on every check
            Role::findByName('developer', 'admin')->givePermissionTo('orders.view');
            Permission::findByName('orders.manage', 'admin');
            $dev->givePermissionTo('orders.manage');
            $dev->unsetRelation('roles')->unsetRelation('permissions');
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $this->assertFalse(AdminAccess::allows($dev->fresh(), 'orders.view'));
            $this->assertFalse(AdminAccess::allows($dev->fresh(), 'orders.manage'));
            $this->assertNotContains('orders.view', AdminAccess::effective($dev->fresh()));
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($dev->fresh(), ['*']);
            $this->getJson(self::ADMIN.'/orders')->assertForbidden();
            $this->getJson(self::ADMIN."/orders/{$order->id}")->assertForbidden();
        }
    }

    public function test_the_developer_dashboard_has_no_order_figures_or_order_numbers(): void
    {
        $order = $this->orderFor();
        $admin = $this->actingAsAdmin('admin');
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->getJson(self::ADMIN.'/dashboard')->assertOk()->assertJsonPath('orders.pending', 0)->assertJsonPath('orders.today', 1);

        $this->actingAsAdmin('developer');
        $res = $this->getJson(self::ADMIN.'/dashboard')->assertOk();
        $res->assertJsonMissingPath('orders');
        $body = $res->getContent();
        $this->assertStringNotContainsString($order->order_number, $body);
        $this->assertStringNotContainsString('revenue', $body);
        $this->assertStringNotContainsString('order.', $body);
        foreach ($res->json('recent_activity') as $row) {
            $this->assertNotSame('Order', $row['subject_type']);
        }
        $this->assertArrayHasKey('low_stock', $res->json('catalog'));
    }

    public function test_the_developer_does_not_get_customers_by_default_but_can_be_given_the_list(): void
    {
        $this->orderFor(['name' => 'Alex', 'phone' => '+1 555 010 0100']);
        $this->actingAsAdmin('developer');

        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
        $this->getJson(self::ADMIN.'/customers/export')->assertForbidden();

        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['customers.view']])->assertOk();
        $this->getJson(self::ADMIN.'/customers')->assertOk()->assertJsonPath('data.0.name', 'Alex');
        $this->getJson(self::ADMIN.'/customers/export')->assertForbidden();   // only the list was switched on

        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => []])->assertOk();
        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
    }

    // ------------------------------------------------------------------ the admin

    public function test_the_admin_is_blocked_from_developer_only_screens(): void
    {
        $this->actingAsAdmin('admin');   // every switchable permission on

        $this->getJson(self::ADMIN.'/access-control')->assertForbidden()->assertJsonPath('message', 'You do not have permission to do this.');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => AdminAccess::all()])->assertForbidden();
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['customers.view']])->assertForbidden();
    }

    public function test_the_admin_can_never_hold_access_manage(): void
    {
        $this->actingAsAdmin('developer');

        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['access.manage']])->assertStatus(422);
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['no.such']])->assertStatus(422);
        $this->putJson(self::ADMIN.'/access-control/nobody', ['permissions' => []])->assertNotFound();
        $this->assertFalse(Role::findByName('admin', 'admin')->hasPermissionTo('access.manage'));

        // even if the row is forced in, the check refuses it
        Role::findByName('admin', 'admin')->givePermissionTo('access.manage');
        $this->assertFalse(AdminAccess::allows($this->makeAdmin('admin', 'client'), 'access.manage'));
    }

    public function test_the_developer_switches_the_admin_permissions_and_the_api_follows_at_once(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['dashboard.view', 'products.view', 'settings.banners']])
            ->assertOk()->assertJsonPath('roles.1.role', 'admin');

        $this->actingAsAdmin('admin', everything: false);
        $this->getJson(self::ADMIN.'/products')->assertOk();
        $this->getJson(self::ADMIN.'/banners')->assertOk();
        $this->getJson(self::ADMIN.'/dashboard')->assertOk();
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Nope'])->assertForbidden();
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
        $this->getJson(self::ADMIN.'/categories')->assertForbidden();
        $this->getJson(self::ADMIN.'/auth/me')->assertJsonPath('admin.permissions', ['dashboard.view', 'products.view', 'settings.banners']);

        // switched back on
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['dashboard.view', 'products.view', 'products.manage']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Yes'])->assertCreated();
        $this->getJson(self::ADMIN.'/banners')->assertForbidden();
    }

    public function test_each_role_can_call_exactly_what_it_has(): void
    {
        foreach (['developer', 'admin-default', 'admin-all', 'admin-none'] as $who) {
            if ($who === 'admin-none') {
                $this->actingAsAdmin('developer');
                $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => []])->assertOk();
                $admin = $this->actingAsAdmin('admin', everything: false);
                AdminAccess::setRolePermissions('admin', []);
            } elseif ($who === 'admin-default') {
                AdminAccess::sync(reset: true);
                $admin = $this->actingAsAdmin('admin', everything: false);
            } elseif ($who === 'admin-all') {
                $admin = $this->actingAsAdmin('admin');
            } else {
                $admin = $this->actingAsAdmin('developer');
            }
            $admin->unsetRelation('roles')->unsetRelation('permissions');
            $allowed = AdminAccess::effective($admin);

            foreach ($this->endpoints() as [$permission, $method, $uri, $body]) {
                $res = $this->json($method, self::ADMIN.$uri, $body);
                if (in_array($permission, $allowed, true)) {
                    $this->assertNotContains($res->getStatusCode(), [401, 403], "$who should be allowed: $method $uri ($permission) got {$res->getStatusCode()}");
                } else {
                    $res->assertForbidden();
                }
            }
        }
    }

    public function test_settings_tabs_are_guarded_one_by_one(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['settings.general', 'settings.footer']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);

        // the page only lists the tabs the admin may open, and only their values
        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['general', 'footer'], collect($res->json('tabs'))->pluck('key')->all());
        $this->assertArrayHasKey('store_name', $res->json('values'));
        $this->assertArrayHasKey('footer_text', $res->json('values'));
        $this->assertArrayNotHasKey('currency_code', $res->json('values'));
        $this->assertArrayNotHasKey('delivery_fee', $res->json('values'));
        $this->assertArrayNotHasKey('seo_meta_title', $res->json('values'));

        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Allowed', 'footer_text' => 'Hello'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['currency_code' => 'EUR'])->assertForbidden();
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Mixed', 'seo_meta_title' => 'No'])->assertForbidden();
        $this->postJson(self::ADMIN.'/settings/logo', [])->assertForbidden();
        $this->postJson(self::ADMIN.'/settings/share-image', [])->assertForbidden();
        $this->getJson(self::ADMIN.'/banners')->assertForbidden();

        $this->assertSame('Allowed', app(\App\Domain\Support\Settings::class)->get('store_name'));
        $this->assertSame('USD', app(\App\Domain\Support\Settings::class)->get('currency_code'));
    }

    public function test_the_access_control_screen_data(): void
    {
        $this->actingAsAdmin('developer');
        $roles = collect($this->getJson(self::ADMIN.'/access-control')->assertOk()->json('roles'))->keyBy('role');

        $this->assertSame(['developer', 'admin'], $roles->keys()->all());
        $adminKeys = collect($roles['admin']['groups'])->flatMap(fn ($g) => collect($g['permissions'])->pluck('key'))->all();
        $this->assertNotContains('access.manage', $adminKeys);
        $this->assertContains('settings.banners', $adminKeys);
        $this->assertSame(
            collect(AdminAccess::all())->reject(fn ($p) => $p === 'access.manage')->sort()->values()->all(),
            collect($adminKeys)->sort()->values()->all(),
        );
        $this->assertTrue(collect($roles['admin']['groups'])->flatMap(fn ($g) => $g['permissions'])->every(fn ($p) => $p['locked'] === false));

        // the developer's own list has no orders; only the customers switches can be changed
        $devPermissions = collect($roles['developer']['groups'])->flatMap(fn ($g) => $g['permissions'])->keyBy('key');
        $this->assertFalse($devPermissions->has('orders.view'));
        $this->assertFalse($devPermissions['customers.view']['locked']);
        $this->assertTrue($devPermissions['products.manage']['locked']);
        $this->assertTrue($devPermissions['access.manage']['locked']);
        $this->assertTrue($devPermissions['access.manage']['enabled']);
    }

    // ------------------------------------------------------------------ auth basics

    public function test_the_permission_is_checked_before_the_record_is_looked_up(): void
    {
        $this->actingAsAdmin('admin', everything: false);

        // a forbidden call is 403 whether or not the record exists: nothing leaks
        $this->putJson(self::ADMIN.'/products/999999', ['name' => 'x'])->assertForbidden();
        $this->deleteJson(self::ADMIN.'/banners/999999')->assertForbidden();
    }

    public function test_an_admin_without_a_role_can_log_in_but_do_nothing(): void
    {
        AdminAccess::sync();
        $admin = Admin::create(['username' => 'norole', 'name' => 'No Role', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson(self::ADMIN.'/auth/me')->assertOk()->assertJsonPath('admin.permissions', []);
        $this->getJson(self::ADMIN.'/dashboard')->assertForbidden();
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
        $this->getJson(self::ADMIN.'/settings')->assertForbidden();
    }

    public function test_guests_get_401_not_403_and_a_deactivated_admin_loses_access_at_once(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN.'/products')->assertUnauthorized();
        $this->putJson(self::ADMIN.'/access-control/admin', [])->assertUnauthorized();

        $admin = $this->actingAsAdmin('admin');
        $this->getJson(self::ADMIN.'/products')->assertOk();
        $admin->forceFill(['is_active' => false])->save();
        $this->getJson(self::ADMIN.'/products')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ audit log

    public function test_permission_changes_are_written_to_the_audit_log(): void
    {
        $dev = $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['dashboard.view', 'settings.seo']])->assertOk();

        $log = DB::table('audit_logs')->where('action', 'access.updated')->first();
        $this->assertNotNull($log);
        $this->assertSame('admin', $log->actor_type);
        $this->assertSame($dev->id, (int) $log->actor_id);
        $this->assertSame('Role', $log->subject_type);
        $changes = json_decode($log->changes, true);
        $this->assertSame('admin', $changes['role']);
        $this->assertContains('settings.seo', $changes['granted']);
        $this->assertContains('orders.view', $changes['revoked']);
    }

    public function test_admin_changes_are_audited(): void
    {
        $admin = $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Audited'])->assertOk();
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Audit tag'])->assertCreated();
        $this->postJson(self::ADMIN.'/categories', ['name' => 'Audit cat'])->assertCreated();

        foreach (['settings.updated', 'tag.created', 'category.created'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'actor_type' => 'admin', 'actor_id' => $admin->id]);
        }
    }
}

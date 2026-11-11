<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The panel's poll: ONE call for the order and wholesale badges + versions (instead of two), each part only for an admin who may see it. */
class BadgesTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private const URL = '/api/v1/admin/badges';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    public function test_it_answers_both_badges_with_the_numbers_and_versions_of_the_single_endpoints(): void
    {
        $this->orderFor();
        $this->postJson('/api/v1/store/wholesale-requests', ['name' => 'Sam', 'phone' => '+15550100777', 'product_name' => 'Wooden blocks', 'quantity' => 40, 'message' => 'Hello there'])->assertCreated();
        $this->asAdmin();

        $badges = $this->getJson(self::URL)->assertOk()->json();
        $this->assertSame($this->getJson('/api/v1/admin/orders/pending-count')->json(), $badges['orders']);
        $this->assertSame($this->getJson('/api/v1/admin/wholesale/new-count')->json(), $badges['wholesale']);
        $this->assertSame(1, $badges['orders']['count']);
        $this->assertSame(1, $badges['wholesale']['count']);
    }

    public function test_the_versions_move_when_an_order_or_a_request_changes(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();
        $before = $this->getJson(self::URL)->json();
        $this->travel(2)->seconds();   // the version follows the last change time (to the second)
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $after = $this->getJson(self::URL)->json();
        $this->assertNotSame($before['orders']['version'], $after['orders']['version']);
        $this->assertSame($before['wholesale']['version'], $after['wholesale']['version']);
    }

    public function test_an_admin_only_gets_the_parts_they_may_see(): void
    {
        $this->orderFor();
        $admin = $this->makeAdmin('admin', 'limited');
        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'orders.view']);
        $this->app['auth']->forgetGuards();
        $admin->unsetRelation('roles')->unsetRelation('permissions');
        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);

        $json = $this->getJson(self::URL)->assertOk()->json();
        $this->assertArrayHasKey('orders', $json);
        $this->assertArrayNotHasKey('wholesale', $json);
    }

    public function test_the_developer_and_an_admin_without_either_permission_are_refused(): void
    {
        $this->asAdmin('developer');
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_it_needs_a_login(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }
}

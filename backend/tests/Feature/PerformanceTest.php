<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Http\Middleware\ServerTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** What keeps the panel fast: the covering index, a fixed number of dashboard queries whatever the orders, the Server-Timing header of local development. */
class PerformanceTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    public function test_the_dashboard_has_a_covering_index_on_the_orders(): void
    {
        $this->assertTrue(Schema::hasIndex('orders', 'orders_created_status_pay_total_index'));
        $this->assertTrue(Schema::hasIndex('orders', ['created_at', 'status', 'payment_status', 'grand_total']));
    }

    public function test_the_dashboard_costs_a_fixed_number_of_queries_and_adds_up_the_same(): void
    {
        $this->orderFor();
        $this->asAdmin();
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/dashboard?range=all')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();

        $a = $this->orderFor(['phone' => '+15550100002']);
        $b = $this->orderFor(['phone' => '+15550100003']);
        $this->asAdmin();   // placing an order as a guest logs the admin out of the test
        $this->postJson("/api/v1/admin/orders/{$a->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$b->id}/status", ['status' => 'rejected'])->assertOk();
        $this->orderFor(['phone' => '+15550100004']);
        $this->asAdmin();
        DB::flushQueryLog();
        $json = $this->getJson('/api/v1/admin/dashboard?range=all')->assertOk()->json();
        $this->assertSame($one, count(DB::getQueryLog()), 'the dashboard runs the same queries however many orders there are');

        $k = $json['orders']['kpis'];
        $revenue = Order::query()->whereNotIn('status', ['cancelled', 'rejected'])->sum('grand_total');
        $this->assertEquals(4, $k['total_orders']);
        $this->assertEquals(2, $k['pending_count']);
        $this->assertEquals(2, $k['cancelled_rejected_count']);
        $this->assertEquals(round($revenue, 2), $k['sales']);
        $this->assertEquals(0, $k['revenue']);
        $this->assertEquals(round($revenue / 2, 2), $k['average_order_value']);
        $this->assertCount(1, array_filter($json['orders']['series']['points'], fn ($p) => $p['orders'] > 0));
        $this->assertEquals(2, array_sum(array_column($json['orders']['series']['points'], 'orders')), 'the chart counts the live orders only');
    }

    public function test_server_timing_reports_the_total_boot_and_database_time(): void
    {
        $response = (new ServerTiming)->handle(Request::create('/x'), function () {
            DB::select('select 1');

            return response('ok');
        });

        $this->assertMatchesRegularExpression('/^total;dur=[\d.]+, boot;dur=[\d.]+, db;dur=[\d.]+;desc="1 queries"$/', $response->headers->get('Server-Timing'));
    }

    public function test_the_header_is_only_added_in_local_development(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->getJson('/api/v1/store/settings')->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_the_local_database_connection_is_persistent_only_on_request(): void
    {
        $this->assertArrayNotHasKey(\PDO::ATTR_PERSISTENT, config('database.connections.mysql.options'));   // never in tests or production by default
    }
}

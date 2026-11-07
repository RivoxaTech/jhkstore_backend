<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Order\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The dashboard of Phase B: date ranges in the store's time zone, KPI cards, the chart series, top sellers, newest orders, low stock, and a section per permission. */
class DashboardRangeTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private string $originalZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalZone = date_default_timezone_get();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    private function zone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    /** Places an order "at" a moment in shop time (the clock is moved there). */
    private function orderAt(string $when, int $qty = 1, array $variant = [], array $body = []): Order
    {
        Carbon::setTestNow(Carbon::parse($when, config('app.timezone')));
        $variantId = $this->variantOf($this->shopProduct($variant));
        $number = $this->placeOrder($this->orderBody([$variantId => $qty], $body))->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function dashboard(string $range = ''): \Illuminate\Testing\TestResponse
    {
        $this->actingAsAdmin();

        return $this->getJson(self::ADMIN.'/dashboard'.($range !== '' ? "?range=$range" : ''))->assertOk();
    }

    public function test_the_kpi_cards_add_up_over_the_range(): void
    {
        $this->orderAt('2026-11-03 09:00');                                            // 160, stays pending
        $b = $this->orderAt('2026-11-03 10:00', 2);                                    // 320, confirmed
        $c = $this->orderAt('2026-11-03 11:00');                                       // cancelled
        $d = $this->orderAt('2026-11-03 12:00');                                       // rejected
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'B', 'number' => '1', 'active' => true]]);
        $e = $this->orderAt('2026-11-03 13:00', 1, [], ['payment_method' => 'bank_transfer']);   // waits for the screenshot
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$b->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$c->id}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$d->id}/status", ['status' => 'rejected', 'reason' => 'x'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-03 18:00', config('app.timezone')));

        $k = $this->dashboard('today')->json('orders.kpis');

        $this->assertSame(5, $k['total_orders']);
        $this->assertEquals(160 + 320 + 160, $k['revenue']);                 // pending + confirmed + bank order; not the cancelled or the rejected
        $this->assertEquals(round((160 + 320 + 160) / 3, 2), $k['average_order_value']);
        $this->assertSame(2, $k['pending_count']);                            // the first one and the bank order
        $this->assertEquals(160 + 160, $k['pending_amount']);
        $this->assertSame(2, $k['cancelled_rejected_count']);
        $this->assertSame(1, $k['awaiting_proof_count']);                     // the bank order has no screenshot yet

        $this->uploadProof($e)->assertOk();
        $this->assertSame(0, $this->dashboard('today')->json('orders.kpis.awaiting_proof_count'));
    }

    public function test_the_ranges_start_at_the_right_shop_day(): void
    {
        $this->orderAt('2026-10-04 12:00');   // 30 days ago: outside 30d (starts 5 Oct), inside "all"
        $this->orderAt('2026-10-20 12:00');
        $this->orderAt('2026-10-28 12:00');   // inside 7d (starts 28 Oct)
        $this->orderAt('2026-10-27 23:59');   // just outside 7d
        $this->orderAt('2026-11-03 08:00');   // today
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));

        $total = fn (string $r) => $this->dashboard($r)->json('orders.kpis.total_orders');
        $this->assertSame(1, $total('today'));
        $this->assertSame(2, $total('7d'));
        $this->assertSame(4, $total('30d'));
        $this->assertSame(1, $total('month'));       // from 1 November: the October orders are last month
        $this->assertSame(5, $total('all'));
    }

    public function test_the_default_range_is_thirty_days_and_an_unknown_range_is_refused(): void
    {
        $this->orderAt('2026-10-04 12:00');
        $this->orderAt('2026-10-20 12:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));

        $this->assertSame(1, $this->dashboard()->json('orders.kpis.total_orders'));
        $this->assertSame('30d', $this->dashboard()->json('range.key'));
        $this->actingAsAdmin();
        $this->getJson(self::ADMIN.'/dashboard?range=forever')->assertStatus(422)->assertJsonValidationErrors('range');
    }

    public function test_a_non_utc_shop_zone_decides_what_today_is(): void
    {
        $this->zone('Pacific/Auckland');                      // +13 in November
        $this->orderAt('2026-11-03 00:30');                   // 11:30 UTC on 2 Nov, but 3 Nov in the shop
        $this->orderAt('2026-11-02 23:30');                   // 10:30 UTC on 2 Nov: 2 Nov in the shop
        Carbon::setTestNow(Carbon::parse('2026-11-03 09:00', 'Pacific/Auckland'));

        $res = $this->dashboard('today');
        $this->assertSame(1, $res->json('orders.kpis.total_orders'));
        $this->assertSame(1, $res->json('orders.today'));
        $this->assertSame('Pacific/Auckland', $res->json('range.timezone'));
        $this->assertSame('2026-11-03', $res->json('range.from'));
        $this->assertSame('2026-11-03T00:30:00+13:00', $res->json('orders.recent.0.placed_at'));
        $this->assertSame(2, $this->dashboard('7d')->json('orders.kpis.total_orders'));
        $this->assertSame('2026-11-03', collect($this->dashboard('7d')->json('orders.series.points'))->last()['date']);
    }

    public function test_the_series_has_a_point_for_every_day_zeros_included(): void
    {
        $this->orderAt('2026-11-01 10:00', 2);   // 320
        $this->orderAt('2026-11-01 15:00');      // 160
        $this->orderAt('2026-11-03 10:00');      // 160
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));

        $series = $this->dashboard('7d')->json('orders.series');

        $this->assertSame('day', $series['granularity']);
        $this->assertCount(7, $series['points']);
        $this->assertSame('2026-10-28', $series['points'][0]['date']);
        $this->assertSame('2026-11-03', $series['points'][6]['date']);
        $byDate = collect($series['points'])->keyBy('date');
        $this->assertSame(2, $byDate['2026-11-01']['orders']);
        $this->assertEquals(480, $byDate['2026-11-01']['revenue']);
        $this->assertSame(0, $byDate['2026-11-02']['orders']);
        $this->assertEquals(0, $byDate['2026-11-02']['revenue']);
        $this->assertSame(1, $byDate['2026-11-03']['orders']);
    }

    public function test_all_time_groups_a_long_history_by_month(): void
    {
        $this->orderAt('2026-01-15 10:00');
        $this->orderAt('2026-03-20 10:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));

        $series = $this->dashboard('all')->json('orders.series');

        $this->assertSame('month', $series['granularity']);
        $this->assertSame('2026-01', $series['points'][0]['date']);
        $this->assertSame('2026-11', collect($series['points'])->last()['date']);
        $this->assertSame(1, collect($series['points'])->firstWhere('date', '2026-03')['orders']);
        $this->assertSame(2, $this->dashboard('all')->json('orders.kpis.total_orders'));
    }

    public function test_the_top_sellers_count_units_and_leave_out_void_orders(): void
    {
        $a = $this->shopProduct(['stock' => 100], ['name' => 'Alpha']);
        $b = $this->shopProduct(['stock' => 100], ['name' => 'Beta']);
        $c = $this->shopProduct(['stock' => 100], ['name' => 'Gamma']);
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', config('app.timezone')));
        $place = fn ($product, int $qty) => Order::where('order_number', $this->placeOrder($this->orderBody([$this->variantOf($product) => $qty]))->assertCreated()->json('data.order_number'))->first();
        $place($a, 3);
        $place($a, 2);
        $place($b, 4);
        $void = $place($c, 9);
        $others = collect(range(1, 4))->map(fn ($i) => $place($this->shopProduct(['stock' => 100], ['name' => "Minor $i"]), 1));
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$void->id}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();

        $top = $this->dashboard('today')->json('orders.top_products');

        $this->assertCount(5, $top);                                    // five at most
        $this->assertSame(['Alpha', 5], [$top[0]['name'], $top[0]['qty']]);
        $this->assertSame(['Beta', 4], [$top[1]['name'], $top[1]['qty']]);
        $this->assertEquals(5 * 160, $top[0]['revenue']);                // 3 x 160 + 2 x 160: the bulk price needs 5 units in ONE order line
        $this->assertNotContains('Gamma', array_column($top, 'name'));   // cancelled
        $this->assertSame(4, $others->count());
    }

    public function test_the_newest_orders_are_listed_eight_at_most_newest_first(): void
    {
        foreach (range(1, 10) as $i) {
            $this->orderAt('2026-11-03 '.sprintf('%02d', $i).':00');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));

        $recent = $this->dashboard('today')->json('orders.recent');

        $this->assertCount(8, $recent);
        $this->assertGreaterThan($recent[1]['id'], $recent[0]['id']);
        $this->assertSame(['id', 'order_number', 'customer_name', 'total', 'status', 'payment_method', 'payment_status', 'placed_at'], array_keys($recent[0]));
    }

    public function test_low_stock_options_and_product_counts_come_with_products_view(): void
    {
        $this->shopProduct(['stock' => 2, 'low_stock_threshold' => 5], ['name' => 'Almost gone']);
        $this->shopProduct(['stock' => 40], ['name' => 'Plenty']);
        foreach (range(1, 9) as $i) {
            $this->shopProduct(['stock' => 1, 'low_stock_threshold' => 5], ['name' => "Low $i"]);
        }
        $this->makeProduct($this->shopCategory, ['name' => 'Draft one', 'status' => 'draft']);

        $catalog = $this->dashboard()->json('catalog');

        $this->assertSame(10, $catalog['low_stock']);
        $this->assertCount(8, $catalog['low_stock_items']);
        $this->assertSame(1, $catalog['low_stock_items'][0]['available']);          // fewest left first
        $this->assertSame(['variant_id', 'product_id', 'product_name', 'option_name', 'sku', 'available', 'threshold'], array_keys($catalog['low_stock_items'][0]));
        $this->assertSame(11, $catalog['by_status']['active']);
        $this->assertSame(1, $catalog['by_status']['draft']);
        $this->assertSame(12, $catalog['products']);
    }

    // ------------------------------------------------------------------ one section per permission

    public function test_the_developer_gets_catalogue_widgets_and_no_order_customer_or_wholesale_figure(): void
    {
        $order = $this->orderAt('2026-11-03 10:00');
        $this->flushHeaders()->postJson(self::STORE.'/wholesale-requests', ['name' => 'Bulk', 'phone' => '+1 555 300 0001', 'product_name' => 'Boxes', 'quantity' => 100])->assertCreated();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();   // an order line in the activity log
        $this->actingAsAdmin('developer');

        $res = $this->getJson(self::ADMIN.'/dashboard')->assertOk();
        $body = $res->getContent();

        $this->assertNotNull($res->json('catalog'));
        $this->assertNull($res->json('orders'));
        $this->assertNull($res->json('customers'));
        $this->assertNull($res->json('wholesale'));
        $this->assertStringNotContainsString($order->order_number, $body);
        $this->assertStringNotContainsString('revenue', $body);
        $this->assertStringNotContainsString('Alex Morgan', $body);
        foreach ($res->json('recent_activity') as $line) {
            $this->assertStringStartsNotWith('order', $line['action']);
            $this->assertStringStartsNotWith('wholesale', $line['action']);
        }

        // every range keeps it that way
        foreach (array_keys(\App\Domain\Support\Dashboard::RANGES) as $range) {
            $this->assertNull($this->getJson(self::ADMIN."/dashboard?range=$range")->json('orders'));
        }
    }

    public function test_each_section_follows_its_own_permission(): void
    {
        $this->orderAt('2026-11-03 10:00');
        $this->flushHeaders()->postJson(self::STORE.'/wholesale-requests', ['name' => 'Bulk', 'phone' => '+1 555 300 0002', 'product_name' => 'Boxes', 'quantity' => 100])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));
        $this->actingAsAdmin();
        $sections = fn () => collect(['orders', 'catalog', 'wholesale', 'customers'])->filter(fn ($k) => $this->getJson(self::ADMIN.'/dashboard')->json($k) !== null)->values()->all();

        AdminAccess::setRolePermissions('admin', ['dashboard.view']);
        $this->assertSame([], $sections());
        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'orders.view']);
        $this->assertSame(['orders'], $sections());
        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'products.view', 'wholesale.view']);
        $this->assertSame(['catalog', 'wholesale'], $sections());
        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'customers.view']);
        $this->assertSame(['customers'], $sections());
        $this->assertSame(2, $this->getJson(self::ADMIN.'/dashboard?range=today')->json('customers.new'));   // the buyer and the wholesale requester

        AdminAccess::setRolePermissions('admin', ['dashboard.view', 'wholesale.view']);
        $this->assertSame(['new' => 1, 'in_range' => 1], $this->getJson(self::ADMIN.'/dashboard')->json('wholesale'));
    }

    public function test_the_dashboard_needs_only_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 12) as $i) {
            $this->orderAt('2026-11-03 '.sprintf('%02d', $i).':00');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00', config('app.timezone')));
        $this->actingAsAdmin();
        $this->getJson(self::ADMIN.'/dashboard')->assertOk();   // warm
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::ADMIN.'/dashboard?range=30d')->assertOk();
        $few = count(DB::getQueryLog());
        foreach (range(13, 20) as $i) {
            $this->orderAt('2026-11-03 '.sprintf('%02d', $i).':00');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-03 23:00', config('app.timezone')));
        $this->actingAsAdmin();
        DB::flushQueryLog();
        $this->getJson(self::ADMIN.'/dashboard?range=30d')->assertOk();
        $this->assertSame($few, count(DB::getQueryLog()), 'more orders must not mean more queries');
        $this->assertLessThan(40, $few);
    }
}

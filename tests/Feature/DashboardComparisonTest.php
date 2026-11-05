<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Support\Dashboard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Phase C dashboard: the change against the previous period, thumbnails and prices of the top sellers. */
class DashboardComparisonTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orderAt(string $when, int $qty = 1): Order
    {
        Carbon::setTestNow(Carbon::parse($when, config('app.timezone')));
        $number = $this->placeOrder($this->orderBody([$this->variantOf($this->shopProduct()) => $qty]))->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function dashboard(string $range)
    {
        $this->actingAsAdmin();

        return $this->getJson(self::ADMIN."/dashboard?range=$range")->assertOk();
    }

    public function test_the_previous_period_has_the_same_length_and_ends_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00', config('app.timezone')));
        $d = new Dashboard;

        $prev = $d->previousRange($d->range('7d'));
        $this->assertSame(['2026-11-05', '2026-11-11'], [$prev['from']->toDateString(), $prev['to']->toDateString()]);
        $prev = $d->previousRange($d->range('30d'));
        $this->assertSame(['2026-09-20', '2026-10-19'], [$prev['from']->toDateString(), $prev['to']->toDateString()]);
        $prev = $d->previousRange($d->range('today'));
        $this->assertSame(['2026-11-17', '2026-11-17'], [$prev['from']->toDateString(), $prev['to']->toDateString()]);
        $prev = $d->previousRange($d->range('month'));                       // 18 days of November against the first 18 days of October
        $this->assertSame(['2026-10-01', '2026-10-18'], [$prev['from']->toDateString(), $prev['to']->toDateString()]);
        $this->assertNull($d->previousRange($d->range('all')));
    }

    public function test_the_change_is_worked_out_against_the_previous_period(): void
    {
        $this->orderAt('2026-11-10 10:00');                 // previous 7 days (Nov 5 - 11): one order, 160
        $this->orderAt('2026-11-16 10:00', 1);              // this period (Nov 12 - 18): two orders
        $this->orderAt('2026-11-17 10:00', 1);
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00', config('app.timezone')));

        $c = $this->dashboard('7d')->json('orders.comparison');

        $this->assertSame(['2026-11-05', '2026-11-11'], [$c['from'], $c['to']]);
        $this->assertEquals(160, $c['previous']['revenue']);
        $this->assertSame(1, $c['previous']['total_orders']);
        $this->assertEquals(100.0, $c['change']['revenue']);            // 320 against 160
        $this->assertEquals(100.0, $c['change']['total_orders']);       // 2 against 1
        $this->assertEquals(0.0, $c['change']['average_order_value']);  // 160 each time
    }

    public function test_no_change_is_given_when_the_previous_period_had_nothing_or_for_all_time(): void
    {
        $this->orderAt('2026-11-16 10:00');
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00', config('app.timezone')));

        $c = $this->dashboard('7d')->json('orders.comparison');
        $this->assertSame(['revenue' => null, 'total_orders' => null, 'average_order_value' => null], $c['change']);   // nothing to compare with: no percent, no division by zero
        $this->assertNull($this->dashboard('all')->json('orders.comparison'));
    }

    public function test_the_top_sellers_carry_a_thumbnail_slot_and_the_current_price(): void
    {
        $this->orderAt('2026-11-16 10:00', 3);
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00', config('app.timezone')));

        $top = $this->dashboard('7d')->json('orders.top_products.0');

        $this->assertSame(['product_id', 'name', 'qty', 'revenue', 'thumb_url', 'price'], array_keys($top));
        $this->assertSame(3, $top['qty']);
        $this->assertEquals(160, $top['price']);
        $this->assertNull($top['thumb_url']);                           // no picture uploaded
    }

    public function test_the_developer_gets_no_comparison_and_no_order_figure(): void
    {
        $this->orderAt('2026-11-16 10:00');
        $this->actingAsAdmin('developer');
        $res = $this->getJson(self::ADMIN.'/dashboard?range=7d')->assertOk();
        $this->assertArrayNotHasKey('orders', $res->json());
        $this->assertStringNotContainsString('comparison', $res->getContent());
    }
}

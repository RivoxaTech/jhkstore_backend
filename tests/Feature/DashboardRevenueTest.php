<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Sales = what was ordered; Revenue = what was RECEIVED: a verified bank transfer on a confirmed order, cash on delivery once delivered. */
class DashboardRevenueTest extends TestCase
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

    private function kpis(): array
    {
        $this->actingAsAdmin();

        return $this->getJson('/api/v1/admin/dashboard?range=all')->assertOk()->json('orders.kpis');
    }

    private function move($order, string $to): void
    {
        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $to])->assertOk();
    }

    public function test_cash_on_delivery_is_revenue_only_once_it_is_delivered(): void
    {
        $o = $this->orderFor();
        $sales = $this->kpis()['sales'];
        $this->assertGreaterThan(0, $sales);

        $this->assertEquals(0, $this->kpis()['revenue'], 'ordered, nothing received');
        $this->move($o, 'confirmed');
        $this->assertEquals(0, $this->kpis()['revenue'], 'confirmed, still nothing received');
        $this->move($o, 'delivered');   // a confirmed order may go straight to delivered
        $k = $this->kpis();
        $this->assertEquals($sales, $k['revenue']);
        $this->assertEquals($sales, $k['sales']);
    }

    public function test_a_bank_transfer_is_revenue_when_it_is_verified_and_the_order_confirmed(): void
    {
        $o = $this->bankOrder();
        $this->uploadProof($o)->assertOk();
        $this->actingAsAdmin();
        $this->assertEquals(0, $this->kpis()['revenue'], 'screenshot uploaded, not checked');

        $this->postJson("/api/v1/admin/orders/{$o->id}/payment/verify")->assertOk();
        $this->assertEquals(0, $this->kpis()['revenue'], 'verified, but the order is still pending');

        $this->move($o, 'confirmed');
        $this->assertEquals((float) $o->fresh()->grand_total, $this->kpis()['revenue']);
    }

    public function test_cancelling_takes_the_money_out_of_sales_and_revenue(): void
    {
        $o = $this->orderFor();
        $this->move($o, 'confirmed');
        $this->move($o, 'delivered');
        $other = $this->orderFor(['phone' => '+15550100009']);
        $this->move($other, 'cancelled');

        $k = $this->kpis();
        $this->assertEquals((float) $o->fresh()->grand_total, $k['sales']);
        $this->assertEquals((float) $o->fresh()->grand_total, $k['revenue']);
        $this->assertSame(1, $k['cancelled_rejected_count']);
    }
}
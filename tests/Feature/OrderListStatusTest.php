<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The orders list carries what its status dropdown may offer, so it never offers a move the server refuses. */
class OrderListStatusTest extends TestCase
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

    private function place(array $extra = []): Order
    {
        $variant = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variant => 1], $extra))->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function row(Order $order): array
    {
        $this->actingAsAdmin();

        return collect($this->getJson(self::ADMIN.'/orders')->assertOk()->json('data'))->firstWhere('id', $order->id);
    }

    public function test_a_pending_cash_order_offers_confirm_cancel_and_reject(): void
    {
        $row = $this->row($this->place());

        $this->assertSame(['confirmed', 'cancelled', 'rejected'], $row['next_statuses']);
        $this->assertNull($row['blocked_reason']);
    }

    public function test_the_list_follows_the_order_through_its_steps_and_ends_with_no_moves(): void
    {
        $order = $this->place();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(['packed', 'cancelled'], $this->row($order)['next_statuses']);

        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'packed'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'shipped', 'courier' => 'FastShip', 'awb_number' => 'AWB1'])->assertOk();
        $this->assertSame(['delivered'], $this->row($order)['next_statuses']);

        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $row = $this->row($order);
        $this->assertSame([], $row['next_statuses']);
        $this->assertNull($row['blocked_reason']);
    }

    public function test_a_bank_transfer_that_is_not_verified_says_why_it_cannot_be_confirmed(): void
    {
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'Test Bank', 'title' => 'Shop', 'number' => '123', 'iban' => '', 'active' => true]]);
        $order = $this->place(['payment_method' => 'bank_transfer']);

        $this->assertSame('payment_not_verified', $this->row($order)['blocked_reason']);
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422);   // the server agrees with the list
    }

    public function test_a_fee_to_confirm_blocks_the_confirmation_in_the_list_too(): void
    {
        $this->setSetting('delivery_method', 'owner_confirms');
        $row = $this->row($this->place());

        $this->assertSame('delivery_fee_pending', $row['blocked_reason']);
    }
}

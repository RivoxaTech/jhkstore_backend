<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Phase A order changes: rejecting with a reason, the admin_note fix, the order list filters and counts, and what the customer sees. */
class OrderChangesTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
    }

    private function setStatus(Order $order, string $status, array $extra = [])
    {
        return $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => $status] + $extra);
    }

    // ------------------------------------------------------------------ reject

    public function test_rejecting_a_pending_order_gives_everything_back_and_tells_the_customer_why(): void
    {
        $contact = ['name' => 'Rita', 'phone' => '+1 555 321 0001'];
        $variant = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variant => 3], ['customer' => $this->contact($contact)]))->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->first();
        $this->assertSame(47, $this->stockOf($variant)->stock_qty);
        $this->assertSame(1, DB::table('customers')->where('id', $order->customer_id)->value('orders_count'));
        $admin = $this->asAdmin();

        $res = $this->setStatus($order, 'rejected', ['reason' => 'We do not deliver to your street'])->assertOk();

        $res->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'We do not deliver to your street')->assertJsonPath('data.actions.allowed', [])
            ->assertJsonPath('data.actions.can_reject', false)->assertJsonPath('data.actions.can_cancel', false)->assertJsonPath('data.actions.next_status', null);
        $order->refresh();
        $this->assertSame('rejected', $order->status->value);
        $this->assertNotNull($order->rejected_at);
        $this->assertSame('We do not deliver to your street', $order->reject_reason);
        $this->assertNull($order->cancelled_at);
        $this->assertSame('cancelled', $order->payment_status->value);
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);                       // the stock came back
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'cancel', 'qty_change' => 3, 'reference_id' => $order->id, 'note' => "Order {$number} rejected"]);
        $customer = DB::table('customers')->where('id', $order->customer_id)->first();
        $this->assertSame(0, $customer->orders_count);                                    // the customer's totals were reversed
        $this->assertEquals(0, $customer->total_spent);
        $this->assertSame('cancelled', $order->items()->first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.rejected', 'subject_id' => $order->id, 'actor_id' => $admin->id]);
        $last = collect($res->json('data.timeline'))->last();
        $this->assertSame(['pending', 'rejected', 'Rejected: We do not deliver to your street'], [$last['from'], $last['status'], $last['note']]);

        // the customer sees it with the reason
        $this->lookup($number, '+1 555 321 0001')->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_label', 'Rejected')
            ->assertJsonPath('data.reject_reason', 'We do not deliver to your street')->assertJsonPath('data.cancel_reason', null)
            ->assertJsonPath('data.timeline.1.note', 'Rejected: We do not deliver to your street');
    }

    public function test_rejecting_needs_a_reason_of_at_most_300_characters(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();

        $this->setStatus($order, 'rejected')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->setStatus($order, 'rejected', ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->setStatus($order, 'rejected', ['reason' => str_repeat('r', 301)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('pending', $order->fresh()->status->value);

        $this->setStatus($order, 'rejected', ['reason' => str_repeat('r', 300)])->assertOk();
        $this->assertSame(300, strlen($order->fresh()->reject_reason));
    }

    public function test_only_a_pending_order_can_be_rejected_and_a_rejected_order_is_final(): void
    {
        $confirmed = $this->orderFor();
        $rejected = $this->orderFor();
        $this->asAdmin();
        $this->setStatus($confirmed, 'confirmed')->assertOk();
        $this->setStatus($rejected, 'rejected', ['reason' => 'No'])->assertOk();

        $this->setStatus($confirmed, 'rejected', ['reason' => 'Too late'])->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->assertSame('confirmed', $confirmed->fresh()->status->value);

        foreach (['pending' => 422, 'confirmed' => 422, 'packed' => 422, 'cancelled' => 422, 'rejected' => 422] as $to => $status) {
            $extra = in_array($to, ['cancelled', 'rejected'], true) ? ['reason' => 'x'] : [];
            $res = $this->setStatus($rejected, $to, $extra);
            $res->assertStatus($status);   // `pending` is not even a target (validation); the others are refused by the rules
        }
        $this->assertSame('rejected', $rejected->fresh()->status->value);
        $this->assertSame(50 - 1, $this->stockOf($confirmed->items()->first()->variant_id)->stock_qty);
    }

    public function test_cancel_keeps_its_rules(): void
    {
        $pending = $this->orderFor();
        $packed = $this->orderFor();
        $shipped = $this->orderFor();
        $this->asAdmin();
        foreach (['confirmed', 'packed'] as $s) {
            $this->setStatus($packed, $s)->assertOk();
        }
        foreach (['confirmed', 'packed'] as $s) {
            $this->setStatus($shipped, $s)->assertOk();
        }
        $this->setStatus($shipped, 'shipped', ['courier' => 'Fast', 'awb_number' => 'A1'])->assertOk();

        $this->setStatus($pending, 'cancelled')->assertStatus(422)->assertJsonValidationErrors('reason');   // a reason is still needed
        $this->setStatus($pending, 'cancelled', ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('data.cancel_reason', 'Duplicate')->assertJsonPath('data.reject_reason', null);
        $this->setStatus($packed, 'cancelled', ['reason' => 'Out of stock'])->assertOk();
        $this->setStatus($shipped, 'cancelled', ['reason' => 'Too late'])->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->assertNotNull($pending->fresh()->cancelled_at);
        $this->assertNull($pending->fresh()->rejected_at);
    }

    public function test_a_pending_order_offers_confirm_cancel_and_reject(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();

        $actions = $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()->json('data.actions');

        $this->assertSame(['confirmed', 'cancelled', 'rejected'], $actions['allowed']);
        $this->assertSame('confirmed', $actions['next_status']);
        $this->assertTrue($actions['can_reject']);
        $this->assertTrue($actions['can_cancel']);
        $this->assertNull($actions['blocked_reason']);
        $this->assertFalse($actions['needs_delivery_fee']);
        $this->assertFalse($actions['can_verify_payment']);
    }

    // ------------------------------------------------------------------ the note (regression)

    public function test_a_note_request_without_the_field_is_refused_and_does_not_clear_the_note(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'Call before delivery'])->assertOk();

        // before the fix a body like {"note": "..."} answered 200 and CLEARED the stored note
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['note' => 'typo in the field name'])->assertStatus(422)->assertJsonValidationErrors('admin_note');
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", [])->assertStatus(422)->assertJsonValidationErrors('admin_note');
        $this->assertSame('Call before delivery', $order->fresh()->admin_note);

        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'Changed'])->assertOk()->assertJsonPath('data.admin_note', 'Changed');
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => null])->assertOk()->assertJsonPath('data.admin_note', null);   // clearing is explicit
        $this->assertNull($order->fresh()->admin_note);
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'Back again'])->assertOk();
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => ''])->assertOk();
        $this->assertNull($order->fresh()->admin_note);
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => str_repeat('n', 2001)])->assertStatus(422);
    }

    // ------------------------------------------------------------------ the list

    public function test_the_list_filters_by_status_payment_method_payment_status_and_search_with_counts_per_status(): void
    {
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'B', 'number' => '1', 'active' => true]]);
        $cod = $this->orderFor(['name' => 'Cora Cash', 'phone' => '+1 555 400 0001']);
        $rejected = $this->orderFor(['name' => 'Rex Rejected', 'phone' => '+1 555 400 0002']);
        $bank = $this->bankOrder(['name' => 'Bea Bank', 'phone' => '+1 555 400 0003']);
        $bankRejected = $this->bankOrder(['name' => 'Bob Bank', 'phone' => '+1 555 400 0004']);
        $this->asAdmin();
        $this->setStatus($rejected, 'rejected', ['reason' => 'No'])->assertOk();
        $this->setStatus($bankRejected, 'rejected', ['reason' => 'No'])->assertOk();
        $names = fn (string $query) => collect($this->getJson(self::ADMIN.'/orders'.$query)->assertOk()->json('data'))->pluck('customer.name')->sort()->values()->all();

        $this->assertSame(['Bea Bank', 'Bob Bank', 'Cora Cash', 'Rex Rejected'], $names(''));
        $this->assertSame(['Bob Bank', 'Rex Rejected'], $names('?status=rejected'));
        $this->assertSame(['Bea Bank', 'Cora Cash'], $names('?status=pending'));
        $this->assertSame(['Bea Bank', 'Bob Bank'], $names('?payment_method=bank_transfer'));
        $this->assertSame(['Cora Cash', 'Rex Rejected'], $names('?payment_method=cod'));
        $this->assertSame(['Bea Bank'], $names('?payment_status=awaiting_payment'));      // Bob's was voided by the rejection
        $this->assertSame(['Bea Bank'], $names('?payment_method=bank_transfer&status=pending'));
        $this->assertSame(['Bea Bank'], $names('?search=%2B15554000003'));                 // by phone (a name search needs the committed FULLTEXT index: see OrderSearchTest)
        $this->assertSame(['Bea Bank'], $names('?q=%2B15554000003'));                      // `q` still works
        $this->assertSame([$cod->order_number], collect($this->getJson(self::ADMIN.'/orders?search='.$cod->order_number)->json('data'))->pluck('order_number')->all());

        // the counts per status follow every filter except the status itself (they feed the tabs)
        $all = $this->getJson(self::ADMIN.'/orders')->json('counts');
        $this->assertEquals(['pending' => 2, 'rejected' => 2], collect($all)->sortKeys()->all());
        $bankTabs = $this->getJson(self::ADMIN.'/orders?payment_method=bank_transfer&status=rejected')->json('counts');
        $this->assertEquals(['pending' => 1, 'rejected' => 1], collect($bankTabs)->sortKeys()->all());

        $this->getJson(self::ADMIN.'/orders?payment_method=card')->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->getJson(self::ADMIN.'/orders?payment_status=lost')->assertStatus(422)->assertJsonValidationErrors('payment_status');
        $this->getJson(self::ADMIN.'/orders?status=teleported')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_list_still_costs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 6) as $i) {
            $this->orderFor();
        }
        $this->asAdmin();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ADMIN.'/orders?per_page=50&payment_method=cod&search=Alex')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->getJson(self::ADMIN.'/orders')->assertOk();   // warm up
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->asAdmin();
            $this->orderFor();
        }
        $this->asAdmin();

        $this->assertSame($few, $count());
    }

    // ------------------------------------------------------------------ what the customer sees

    public function test_the_customer_answer_grew_but_kept_what_it_had(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 600 0001']);

        $data = $this->lookup($order->order_number, '+1 555 600 0001')->assertOk()->json('data');

        foreach (['id', 'order_number', 'status', 'status_label', 'placed_at', 'estimated_delivery_date', 'items_count', 'grand_total', 'payment_method', 'payment_method_label', 'payment_status',
            'customer', 'customer_note', 'cancel_reason', 'items', 'shipping_address', 'billing_address', 'totals', 'tracking', 'timeline',
            'payment', 'delivery_fee_pending', 'reject_reason', 'store_phone', 'whatsapp_url'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
        }
        $this->assertSame('cod', $data['payment']['method']);
        $this->assertSame('cod_pending', $data['payment']['status']);
        $this->assertSame('none', $data['payment']['proof_status']);
        $this->assertFalse($data['payment']['can_upload_proof']);
        $this->assertArrayNotHasKey('accounts', $data['payment']);
        $this->assertNull($data['whatsapp_url']);
        foreach (['discount_total', 'coupon_code', 'coupon_discount', 'delivery_fee_pending'] as $key) {
            $this->assertArrayHasKey($key, $data['totals'], $key);
        }
    }

    public function test_the_customer_still_cannot_probe_orders_with_a_wrong_phone(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 600 0002']);

        $wrong = $this->lookup($order->order_number, '+1 555 600 9999')->assertNotFound();
        $unknown = $this->lookup('ORD-2026-999999', '+1 555 600 0002')->assertNotFound();

        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    public function test_the_money_of_an_order_with_a_discount_and_a_coupon_adds_up(): void
    {
        $this->setSetting('delivery_fee', 0);
        $a = $this->shopProduct();
        $b = $this->shopProduct();
        $this->makeDiscount(['value' => 50, 'scope' => 'products', 'targets' => [$a->id]]);
        $this->makeCoupon(['code' => 'TWENTY', 'value' => 20]);
        $body = $this->orderBody([$this->variantOf($a) => 1, $this->variantOf($b) => 1], ['coupon_code' => 'TWENTY']);

        $res = $this->placeOrder($body)->assertCreated();

        $t = $res->json('data.totals');
        $this->assertEquals(400, $t['items_total']);                                       // 2 x MRP 200
        $this->assertEquals(80, $t['product_discount']);                                   // MRP -> price
        $this->assertEquals(80, $t['discount_total']);                                     // 50 % of the first line
        $this->assertEquals(32, $t['coupon_discount']);                                    // 20 % of the second
        $this->assertSame('TWENTY', $t['coupon_code']);
        $this->assertEquals(400 - 80 - 80 - 32, $t['subtotal']);
        $this->assertEquals(208, $t['grand_total']);
        $this->assertEquals(80 + 80 + 32, $t['total_savings']);
        $this->assertEqualsWithDelta($t['subtotal'], $t['items_total'] - $t['product_discount'] - $t['bulk_discount'] - $t['discount_total'] - $t['coupon_discount'], 0.001);
        $this->assertSame(['discount', 'coupon'], array_column($res->json('data.items'), 'discount_source'));

        $this->asAdmin();
        $order = Order::where('order_number', $res->json('data.order_number'))->first();
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()->assertJsonPath('data.coupon.code', 'TWENTY')->assertJsonPath('data.totals.coupon_discount', 32);
    }
}

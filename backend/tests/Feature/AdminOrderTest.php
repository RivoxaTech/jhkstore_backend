<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function setStatus(Order $order, string $status, array $extra = [])
    {
        return $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => $status] + $extra);
    }

    /** Moves an order through the whole flow as the admin. */
    private function deliver(Order $order): void
    {
        $this->asAdmin();
        $this->setStatus($order, 'confirmed')->assertOk();
        $this->setStatus($order, 'packed')->assertOk();
        $this->setStatus($order, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'FS-123'])->assertOk();
        $this->setStatus($order, 'delivered')->assertOk();
    }

    // ------------------------------------------------------------------ access

    public function test_the_order_screens_are_for_admins_only(): void
    {
        $order = $this->orderFor();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN.'/orders')->assertStatus(401);
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertStatus(401);
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(401);
        $this->getJson(self::ADMIN.'/settings')->assertStatus(401);
        $this->getJson(self::ADMIN.'/customers')->assertStatus(401);

        $this->assertSame('pending', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_is_paginated_newest_first_with_a_stable_row_shape_and_status_counts(): void
    {
        $orders = collect(range(1, 4))->map(fn ($i) => $this->orderFor(['name' => "Customer $i"], 2));
        $this->asAdmin();
        $this->setStatus($orders[0], 'confirmed')->assertOk();
        $this->setStatus($orders[1], 'cancelled', ['reason' => 'Duplicate'])->assertOk();

        $res = $this->getJson(self::ADMIN.'/orders?per_page=3')->assertOk();

        $this->assertSame($orders->pluck('order_number')->reverse()->values()->take(3)->all(), collect($res->json('data'))->pluck('order_number')->all());
        $res->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 3)->assertJsonPath('counts.pending', 2)->assertJsonPath('counts.confirmed', 1)->assertJsonPath('counts.cancelled', 1);
        $this->assertSame(['id', 'order_number', 'placed_at', 'customer', 'items_count', 'grand_total', 'payment_method', 'payment_method_label', 'payment_status', 'delivery_fee_pending', 'status', 'status_label', 'next_statuses', 'blocked_reason'], array_keys($res->json('data.0')));
        $this->assertSame(['id', 'name', 'email', 'phone'], array_keys($res->json('data.0.customer')));
        $this->assertSame(2, $res->json('data.0.items_count'));

        $this->getJson(self::ADMIN.'/orders?per_page=101')->assertStatus(422);
        $this->assertCount(4, $this->getJson(self::ADMIN.'/orders?per_page=100')->json('data'));
    }

    public function test_the_list_filters_by_status_payment_customer_and_dates(): void
    {
        $alice = ['name' => 'Alice Ahmed', 'phone' => '+1 555 010 1001'];
        $bob = ['name' => 'Bob Baig', 'phone' => '+1 555 010 1002'];
        $first = $this->orderFor($alice);
        $second = $this->orderFor($bob);
        $old = $this->orderFor($alice);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => '2026-01-10 10:00:00', 'placed_at' => '2026-01-10 10:00:00']);
        $this->asAdmin();
        $this->setStatus($first, 'confirmed')->assertOk();
        DB::table('orders')->where('id', $second->id)->update(['payment_status' => 'paid']);

        $numbers = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?'.$q)->assertOk()->json('data'))->pluck('order_number')->sort()->values()->all();
        $only = fn (Order ...$o) => collect($o)->pluck('order_number')->sort()->values()->all();
        $aliceId = Customer::where('phone', '+15550101001')->value('id');

        $this->assertSame($only($first), $numbers('status=confirmed'));
        $this->assertSame($only($second), $numbers('payment_status=paid'));
        $this->assertSame($only($first, $second, $old), $numbers('payment_method=cod'));
        $this->assertSame($only($old), $numbers('from=2026-01-01&to=2026-01-31'));
        $this->assertSame($only($old), $numbers('to=2026-01-10'));
        $this->assertSame($only($first, $second), $numbers('from='.now()->toDateString()));
        $this->assertSame($only($first, $old), $numbers('customer_id='.$aliceId));

        // the status tabs count the other filters too
        $res = $this->getJson(self::ADMIN.'/orders')->json('counts');
        $this->assertSame(['confirmed' => 1, 'pending' => 2], collect($res)->sortKeys()->all());

        $this->getJson(self::ADMIN.'/orders?status=bogus')->assertStatus(422);
        $this->getJson(self::ADMIN.'/orders?from=10-01-2026')->assertStatus(422);
        $this->getJson(self::ADMIN.'/orders?from=2026-02-01&to=2026-01-01')->assertStatus(422);
    }

    public function test_search_finds_orders_by_number_email_phone_and_id(): void
    {
        $a = $this->orderFor(['name' => 'Alice Ahmed', 'phone' => '+1 555 010 1001', 'email' => 'alice@example.test']);
        $b = $this->orderFor(['name' => 'Bob Baig', 'phone' => '+1 555 010 2002', 'email' => 'bob.baig@example.test']);
        $this->asAdmin();
        $find = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?q='.urlencode($q))->assertOk()->json('data'))->pluck('order_number')->all();

        $this->assertSame([$a->order_number], $find($a->order_number));
        $this->assertSame([$b->order_number], $find(strtolower($b->order_number)));
        $this->assertEqualsCanonicalizing([$a->order_number, $b->order_number], $find('ORD-'.now()->year));   // a prefix lists both
        $this->assertSame([$b->order_number], $find('bob.baig@'));
        $this->assertSame([$b->order_number], $find('bob.baig@example.test'));
        $this->assertSame([$a->order_number], $find('+155501010'));
        $this->assertSame([$a->order_number], $find('15550101001'));
        $this->assertSame([$b->order_number], $find((string) $b->id));
        $this->assertSame([], $find('ORD-1999'));
        $this->assertSame([], $find('nobody@nowhere.test'));
        $this->assertSame([$a->order_number], $find('Al'));        // short words fall back to LIKE
    }

    public function test_search_by_a_local_phone_finds_orders_stored_with_the_default_country_code(): void
    {
        $this->setSetting('default_phone_country_code', '+92');
        $a = $this->orderFor(['name' => 'Ali Khan', 'phone' => '0300 1234567']);   // stored as +923001234567
        $this->orderFor(['name' => 'Other', 'phone' => '+92 321 7654321']);
        $this->asAdmin();
        $find = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?q='.urlencode($q))->assertOk()->json('data'))->pluck('order_number')->all();

        $this->assertSame([$a->order_number], $find('0300 1234567'));   // the form the shop's staff and customers type
        $this->assertSame([$a->order_number], $find('0300'));
        $this->assertSame([$a->order_number], $find('+92 300 1234567'));
        $this->assertSame([$a->order_number], $find('923001234'));
    }
    public function test_the_list_costs_a_fixed_number_of_queries(): void
    {
        $contact = ['phone' => '+1 555 010 3003'];
        $this->orderFor($contact);
        $this->asAdmin();
        DB::enableQueryLog();
        $this->getJson(self::ADMIN.'/orders')->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            $this->orderFor($contact);
        }
        $this->asAdmin();
        DB::flushQueryLog();
        $res = $this->getJson(self::ADMIN.'/orders')->assertOk();

        $this->assertCount(5, $res->json('data'));
        $this->assertSame($one, count(DB::getQueryLog()));
    }

    public function test_the_filter_options_are_listed(): void
    {
        $this->asAdmin();

        $this->getJson(self::ADMIN.'/orders/options')->assertOk()
            ->assertJsonPath('statuses', ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'rejected'])
            ->assertJsonPath('payment_methods.0.code', 'cod')
            ->assertJsonPath('payment_methods.1.code', 'bank_transfer');
    }

    // ------------------------------------------------------------------ detail

    public function test_the_detail_shows_everything_about_the_order(): void
    {
        $contact = ['name' => 'Dr. Detail', 'phone' => '+1 555 010 4004', 'email' => 'detail@example.test'];
        $order = $this->orderFor($contact, 5);
        $this->orderFor($contact, 1);
        $this->asAdmin();

        $d = $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()->json('data');

        $this->assertSame($order->order_number, $d['order_number']);
        $this->assertSame('pending', $d['status']);
        $this->assertSame('Dr. Detail', $d['customer']['name']);
        $this->assertSame('detail@example.test', $d['customer']['email']);
        $this->assertSame($order->customer_id, $d['customer']['id']);
        $this->assertSame(2, $d['customer']['orders_count']);
        $this->assertSame(['name', 'phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country'], array_keys($d['shipping_address']));
        $this->assertSame('12 Example Street', $d['shipping_address']['line1']);
        $this->assertSame($d['shipping_address'], $d['billing_address']);

        $item = $d['items'][0];
        $this->assertSame(['id', 'product_id', 'variant_id', 'name', 'option_name', 'sku', 'image_url', 'qty', 'mrp', 'price', 'unit_price', 'bulk_applied', 'discount', 'bulk_discount', 'discount_amount', 'discount_source', 'line_total', 'tax_rate', 'tax_amount', 'status'], array_keys($item));
        $this->assertSame(5, $item['qty']);
        $this->assertEquals(152, $item['unit_price']);
        $this->assertEquals(160, $item['price']);

        $this->assertEquals(1000, $d['totals']['items_total']);
        $this->assertEquals(1060, $d['totals']['grand_total']);
        $this->assertSame('Inclusive of all taxes', $d['totals']['tax_note']);

        $this->assertSame('cod', $d['payment']['method']);
        $this->assertSame('cod_pending', $d['payment']['status']);
        $this->assertEquals(1060, $d['payment']['collect_on_delivery']);

        $this->assertSame('confirmed', $d['actions']['next_status']);
        $this->assertTrue($d['actions']['can_cancel']);
        $this->assertSame(['confirmed', 'cancelled', 'rejected'], $d['actions']['allowed']);
        $this->assertTrue($d['actions']['can_reject']);
        $this->assertSame('customer', $d['timeline'][0]['by']['type']);
        $this->assertSame('Dr. Detail', $d['timeline'][0]['by']['name']);
        $this->assertNull($d['shipment']);
        $this->assertNull($d['admin_note']);
    }

    public function test_an_unknown_order_is_a_404(): void
    {
        $this->asAdmin();
        $this->getJson(self::ADMIN.'/orders/99999')->assertNotFound();
    }

    // ------------------------------------------------------------------ status flow

    public function test_the_order_moves_step_by_step_and_the_timeline_records_who_did_what(): void
    {
        $order = $this->orderFor();
        $admin = $this->asAdmin();

        $this->setStatus($order, 'confirmed', ['note' => 'Stock checked'])->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.actions.next_status', 'packed');
        $this->setStatus($order, 'packed')->assertOk()->assertJsonPath('data.actions.next_status', 'shipped')->assertJsonPath('data.actions.needs_shipping_details', true);
        $res = $this->setStatus($order, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'FS-998877', 'tracking_url' => 'https://track.example.test/FS-998877'])->assertOk();
        $res->assertJsonPath('data.shipment.courier', 'FastShip')->assertJsonPath('data.shipment.awb_number', 'FS-998877')->assertJsonPath('data.actions.next_status', 'delivered');
        $res = $this->setStatus($order, 'delivered')->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.actions.next_status', null)->assertJsonPath('data.actions.can_cancel', false);

        $timeline = collect($res->json('data.timeline'));
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped', 'delivered'], $timeline->pluck('status')->all());
        $this->assertSame('Stock checked', $timeline[1]['note']);
        $this->assertSame('Shipped via FastShip, AWB FS-998877', $timeline[3]['note']);
        $this->assertSame(['admin', $admin->name], [$timeline[1]['by']['type'], $timeline[1]['by']['name']]);

        $fresh = Order::find($order->id);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNotNull($fresh->shipped_at);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame(4, DB::table('audit_logs')->where('subject_type', 'Order')->where('subject_id', $order->id)->where('action', 'order.status_changed')->count());
    }

    public function test_steps_cannot_be_skipped_or_reversed(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();

        $this->setStatus($order, 'packed')->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->setStatus($order, 'delivered')->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->setStatus($order, 'pending')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->setStatus($order, 'confirmed')->assertOk();
        $this->setStatus($order, 'confirmed')->assertStatus(422)->assertJsonPath('message', 'An order that is confirmed cannot be moved to confirmed.');
        $this->setStatus($order, 'nonsense')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->setStatus($order, 'returned')->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('confirmed', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_shipping_asks_for_nothing_but_keeps_the_courier_and_awb_when_given(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');

        $this->setStatus($order, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'A1', 'tracking_url' => 'not a link'])->assertStatus(422)->assertJsonValidationErrors('tracking_url');
        $this->assertSame(0, DB::table('shipments')->count());
        $this->assertSame('packed', DB::table('orders')->where('id', $order->id)->value('status'));

        $this->setStatus($order, 'shipped')->assertOk()->assertJsonPath('data.status', 'shipped');   // no courier, no AWB: the status simply changes
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'carrier' => null, 'awb_number' => null]);
    }

    public function test_delivery_marks_cod_paid(): void
    {
        $order = $this->orderFor([], 5);

        $this->deliver($order);

        $order = Order::find($order->id);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertDatabaseHas('payments', ['payable_id' => $order->id, 'payable_type' => 'order', 'status' => 'cod_collected']);
        $this->assertNotNull(DB::table('payments')->where('payable_id', $order->id)->value('paid_at'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'carrier' => 'FastShip', 'awb_number' => 'FS-123', 'status' => 'delivered']);
    }

    public function test_cancelling_as_admin_restores_the_stock_and_a_reason_is_optional(): void
    {
        $order = $this->orderFor([], 4);
        $variant = $order->items()->value('variant_id');
        $this->assertSame(46, $this->stockOf($variant)->stock_qty);
        $admin = $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');

        $res = $this->setStatus($order, 'cancelled', ['reason' => 'Customer called to cancel'])->assertOk();

        $res->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Customer called to cancel')->assertJsonPath('data.cancelled_by', 'admin')
            ->assertJsonPath('data.payment.status', 'cancelled');
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertSame('Cancelled: Customer called to cancel', collect($res->json('data.timeline'))->last()['note']);
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'cancel', 'qty_change' => 4, 'admin_id' => $admin->id]);

        // nothing more can happen to a cancelled order
        $this->setStatus($order, 'confirmed')->assertStatus(422);
    }

    public function test_a_shipped_or_delivered_order_cannot_be_cancelled(): void
    {
        $order = $this->orderFor();
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');
        $this->setStatus($order, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'A1']);

        $this->setStatus($order, 'cancelled', ['reason' => 'Too late'])->assertStatus(422)->assertJsonPath('message', 'This order is shipped and can no longer be cancelled.');
        $this->setStatus($order, 'delivered')->assertOk();
        $this->setStatus($order, 'cancelled', ['reason' => 'Too late'])->assertStatus(422);
    }

    public function test_delivering_twice_is_refused(): void
    {
        $order = $this->orderFor([], 2);
        $this->deliver($order);

        $this->setStatus($order, 'delivered')->assertStatus(422);
        $this->assertSame(1, DB::table('payments')->where('payable_id', $order->id)->where('status', 'cod_collected')->count());
    }

    public function test_the_customer_sees_the_admin_progress_with_the_order_number_and_phone(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 5005']);
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');
        $this->setStatus($order, 'shipped', ['courier' => 'Leopards', 'awb_number' => 'LEO-1', 'tracking_url' => 'https://track.example.test/1']);

        $res = $this->lookup($order->order_number, '+1 555 010 5005')->assertOk();
        $res->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking.courier', 'Leopards')->assertJsonPath('data.tracking.awb_number', 'LEO-1')
            ->assertJsonPath('data.tracking.tracking_url', 'https://track.example.test/1');
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped'], collect($res->json('data.timeline'))->pluck('status')->all());
    }

    // ------------------------------------------------------------------ note

    public function test_the_admin_can_keep_an_internal_note_the_customer_never_sees(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 6006']);
        $this->asAdmin();

        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'Fragile - double pack'])->assertOk()->assertJsonPath('data.admin_note', 'Fragile - double pack');
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertJsonPath('data.admin_note', 'Fragile - double pack');
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => str_repeat('x', 2001)])->assertStatus(422);
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => ''])->assertOk()->assertJsonPath('data.admin_note', null);

        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'secret'])->assertOk();
        $this->assertStringNotContainsString('secret', $this->lookup($order->order_number, '+1 555 010 6006')->getContent());
    }
}

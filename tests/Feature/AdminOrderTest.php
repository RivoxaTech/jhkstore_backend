<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Reward\Models\RewardTransaction;
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
        $this->setStatus($order, 'shipped', ['courier' => 'TCS', 'awb_number' => 'TCS-123'])->assertOk();
        $this->setStatus($order, 'delivered')->assertOk();
    }

    // ------------------------------------------------------------------ access

    public function test_the_order_screens_are_for_admins_only(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user);

        $this->actAs(null);
        $this->getJson(self::ADMIN.'/orders')->assertStatus(401);
        $this->actAs($user);
        $this->getJson(self::ADMIN.'/orders')->assertStatus(401);
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertStatus(401);
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(401);
        $this->getJson(self::ADMIN.'/settings')->assertStatus(401);

        $this->assertSame('pending', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_is_paginated_newest_first_with_a_stable_row_shape_and_status_counts(): void
    {
        $users = collect(range(1, 4))->map(fn ($i) => $this->customer("u$i@example.test", "Customer $i"));
        $orders = $users->map(fn ($u) => $this->orderFor($u, 2));
        $this->asAdmin();
        $this->setStatus($orders[0], 'confirmed')->assertOk();
        $this->setStatus($orders[1], 'cancelled', ['reason' => 'Duplicate'])->assertOk();

        $res = $this->getJson(self::ADMIN.'/orders?per_page=3')->assertOk();

        $this->assertSame($orders->pluck('order_number')->reverse()->values()->take(3)->all(), collect($res->json('data'))->pluck('order_number')->all());
        $res->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 3)->assertJsonPath('counts.pending', 2)->assertJsonPath('counts.confirmed', 1)->assertJsonPath('counts.cancelled', 1);
        $this->assertSame(['id', 'order_number', 'placed_at', 'customer', 'items_count', 'grand_total', 'payment_method', 'payment_method_label', 'payment_status', 'status', 'status_label'], array_keys($res->json('data.0')));
        $this->assertSame(['id', 'name', 'email', 'phone'], array_keys($res->json('data.0.customer')));
        $this->assertSame(2, $res->json('data.0.items_count'));

        $this->getJson(self::ADMIN.'/orders?per_page=101')->assertStatus(422);
        $this->assertCount(4, $this->getJson(self::ADMIN.'/orders?per_page=100')->json('data'));
    }

    public function test_the_list_filters_by_status_payment_and_dates(): void
    {
        $alice = $this->customer('alice@example.test', 'Alice Ahmed');
        $bob = $this->customer('bob@example.test', 'Bob Baig');
        $standard = $this->orderFor($alice);
        $instadent = $this->orderFor($bob);
        $old = $this->orderFor($alice);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => '2026-01-10 10:00:00', 'placed_at' => '2026-01-10 10:00:00']);
        $this->asAdmin();
        $this->setStatus($standard, 'confirmed')->assertOk();
        DB::table('orders')->where('id', $instadent->id)->update(['payment_status' => 'paid']);

        $numbers = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?'.$q)->assertOk()->json('data'))->pluck('order_number')->sort()->values()->all();
        $only = fn (Order ...$o) => collect($o)->pluck('order_number')->sort()->values()->all();

        $this->assertSame($only($standard), $numbers('status=confirmed'));
        $this->assertSame($only($instadent), $numbers('payment_status=paid'));
        $this->assertSame($only($standard, $instadent, $old), $numbers('payment_method=cod'));
        $this->assertSame($only($old), $numbers('from=2026-01-01&to=2026-01-31'));
        $this->assertSame($only($old), $numbers('to=2026-01-10'));
        $this->assertSame($only($standard, $instadent), $numbers('from='.now()->toDateString()));
        $this->assertSame($only($standard, $old), $numbers('user_id='.$alice->id));

        // the status tabs count the other filters too
        $res = $this->getJson(self::ADMIN.'/orders')->json('counts');
        $this->assertSame(['confirmed' => 1, 'pending' => 2], collect($res)->sortKeys()->all());

        $this->getJson(self::ADMIN.'/orders?status=bogus')->assertStatus(422);
        $this->getJson(self::ADMIN.'/orders?from=10-01-2026')->assertStatus(422);
        $this->getJson(self::ADMIN.'/orders?from=2026-02-01&to=2026-01-01')->assertStatus(422);
    }

    public function test_search_finds_orders_by_number_email_phone_and_id(): void
    {
        $alice = $this->customer('alice@example.test', 'Alice Ahmed');
        $alice->update(['mobile' => '03001112233']);
        $bob = $this->customer('bob.baig@example.test', 'Bob Baig');
        $bob->update(['mobile' => '03214445566']);
        $a = $this->orderFor($alice);
        $b = $this->orderFor($bob);
        $this->asAdmin();
        $find = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?q='.urlencode($q))->assertOk()->json('data'))->pluck('order_number')->all();

        $this->assertSame([$a->order_number], $find($a->order_number));
        $this->assertSame([$b->order_number], $find(strtolower($b->order_number)));
        $this->assertEqualsCanonicalizing([$a->order_number, $b->order_number], $find('DK-'.now()->year));   // a prefix lists both
        $this->assertSame([$b->order_number], $find('bob.baig@'));
        $this->assertSame([$b->order_number], $find('bob.baig@example.test'));
        $this->assertSame([$a->order_number], $find('0300111'));
        $this->assertSame([$a->order_number], $find('0300 1112233'));
        $this->assertSame([$b->order_number], $find((string) $b->id));
        $this->assertSame([], $find('DK-1999'));
        $this->assertSame([], $find('nobody@nowhere.test'));
        $this->assertSame([$a->order_number], $find('Al'));        // short words fall back to LIKE
    }

    public function test_the_list_costs_a_fixed_number_of_queries(): void
    {
        $user = $this->customer();
        $this->orderFor($user);
        $this->asAdmin();
        DB::enableQueryLog();
        $this->getJson(self::ADMIN.'/orders')->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            $this->orderFor($user);
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
            ->assertJsonPath('statuses', ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'])
            ->assertJsonPath('payment_methods.0.code', 'cod');
    }

    // ------------------------------------------------------------------ detail

    public function test_the_detail_shows_everything_about_the_order(): void
    {
        $user = $this->customer('detail@example.test', 'Dr. Detail');
        $this->address($user, ['gst_number' => '27ABCDE1234F1Z5']);
        $this->giveCoins($user, 100);
        $order = $this->orderFor($user, 5, [], 50);
        $this->orderFor($user, 1);
        $this->asAdmin();

        $d = $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()->json();
        $d = $d['data'];

        $this->assertSame($order->order_number, $d['order_number']);
        $this->assertSame('pending', $d['status']);
        $this->assertSame('Dr. Detail', $d['customer']['name']);
        $this->assertSame('detail@example.test', $d['customer']['email']);
        $this->assertSame($user->id, $d['customer']['id']);
        $this->assertSame(2, $d['customer']['orders_count']);
        $this->assertSame('27ABCDE1234F1Z5', $d['customer']['gst_number']);
        $this->assertSame(['name', 'phone', 'alt_phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country', 'gst_number', 'type', 'latitude', 'longitude'], array_keys($d['shipping_address']));
        $this->assertSame('Smile Clinic, Shop 4', $d['shipping_address']['line1']);
        $this->assertSame('27ABCDE1234F1Z5', $d['billing_address']['gst_number']);

        $item = $d['items'][0];
        $this->assertSame(['id', 'product_id', 'variant_id', 'name', 'option_name', 'sku', 'image_url', 'brand', 'qty', 'mrp', 'price', 'unit_price', 'bulk_applied', 'discount', 'bulk_discount', 'line_total', 'tax_rate', 'tax_amount', 'coins_earned', 'expiry_date', 'status'], array_keys($item));
        $this->assertSame(5, $item['qty']);
        $this->assertEquals(152, $item['unit_price']);
        $this->assertEquals(160, $item['price']);

        $this->assertEquals(1000, $d['totals']['items_total']);
        $this->assertSame(50, $d['totals']['coins_redeemed']);
        $this->assertEquals(50, $d['totals']['coins_discount']);
        $this->assertEquals(1010, $d['totals']['grand_total']);
        $this->assertSame('Inclusive of all taxes', $d['totals']['tax_note']);

        $this->assertSame('cod', $d['payment']['method']);
        $this->assertSame('cod_pending', $d['payment']['status']);
        $this->assertEquals(1010, $d['payment']['collect_on_delivery']);

        $this->assertSame('confirmed', $d['actions']['next_status']);
        $this->assertTrue($d['actions']['can_cancel']);
        $this->assertSame(['confirmed', 'cancelled'], $d['actions']['allowed']);
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
        $order = $this->orderFor($this->customer());
        $admin = $this->asAdmin();

        $this->setStatus($order, 'confirmed', ['note' => 'Stock checked'])->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.actions.next_status', 'packed');
        $this->setStatus($order, 'packed')->assertOk()->assertJsonPath('data.actions.next_status', 'shipped')->assertJsonPath('data.actions.needs_shipping_details', true);
        $res = $this->setStatus($order, 'shipped', ['courier' => 'TCS', 'awb_number' => 'TCS-998877', 'tracking_url' => 'https://track.example.test/TCS-998877'])->assertOk();
        $res->assertJsonPath('data.shipment.courier', 'TCS')->assertJsonPath('data.shipment.awb_number', 'TCS-998877')->assertJsonPath('data.actions.next_status', 'delivered');
        $res = $this->setStatus($order, 'delivered')->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.actions.next_status', null)->assertJsonPath('data.actions.can_cancel', false);

        $timeline = collect($res->json('data.timeline'));
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped', 'delivered'], $timeline->pluck('status')->all());
        $this->assertSame('Stock checked', $timeline[1]['note']);
        $this->assertSame('Shipped via TCS, AWB TCS-998877', $timeline[3]['note']);
        $this->assertSame(['admin', $admin->name], [$timeline[1]['by']['type'], $timeline[1]['by']['name']]);

        $fresh = Order::find($order->id);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNotNull($fresh->shipped_at);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame(4, DB::table('audit_logs')->where('subject_type', 'Order')->where('subject_id', $order->id)->where('action', 'order.status_changed')->count());
    }

    public function test_steps_cannot_be_skipped_or_reversed(): void
    {
        $order = $this->orderFor($this->customer());
        $this->asAdmin();

        $this->setStatus($order, 'packed')->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->setStatus($order, 'delivered')->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->setStatus($order, 'pending')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->setStatus($order, 'confirmed')->assertOk();
        $this->setStatus($order, 'confirmed')->assertStatus(422)->assertJsonPath('message', 'An order that is confirmed cannot be moved to confirmed.');
        $this->setStatus($order, 'nonsense')->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('confirmed', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_shipping_needs_a_courier_and_an_awb(): void
    {
        $order = $this->orderFor($this->customer());
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');

        $this->setStatus($order, 'shipped')->assertStatus(422)->assertJsonValidationErrors(['courier', 'awb_number']);
        $this->setStatus($order, 'shipped', ['courier' => 'TCS'])->assertStatus(422)->assertJsonValidationErrors('awb_number');
        $this->setStatus($order, 'shipped', ['courier' => 'TCS', 'awb_number' => 'A1', 'tracking_url' => 'not a link'])->assertStatus(422)->assertJsonValidationErrors('tracking_url');
        $this->assertSame(0, DB::table('shipments')->count());
        $this->assertSame('packed', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_delivery_marks_cod_paid_and_credits_the_coins_that_were_earned(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user, 5);   // earns 5 x 5 = 25 coins
        $this->assertDatabaseMissing('reward_transactions', ['user_id' => $user->id, 'type' => 'earn']);
        $this->assertNull(DB::table('reward_wallets')->where('user_id', $user->id)->first());

        $this->deliver($order);

        $order = Order::find($order->id);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertDatabaseHas('payments', ['payable_id' => $order->id, 'payable_type' => 'order', 'status' => 'cod_collected']);
        $this->assertNotNull(DB::table('payments')->where('payable_id', $order->id)->value('paid_at'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'carrier' => 'TCS', 'awb_number' => 'TCS-123', 'status' => 'delivered']);

        $earn = RewardTransaction::where('user_id', $user->id)->where('type', 'earn')->first();
        $this->assertSame(25, $earn->coins);
        $this->assertSame(25, $earn->remaining);
        $this->assertSame('available', $earn->status);
        $this->assertSame($order->id, $earn->source_id);
        $this->assertSame(now()->addDays(365)->toDateString(), $earn->expires_at->toDateString());
        $wallet = DB::table('reward_wallets')->where('user_id', $user->id)->first();
        $this->assertSame(25, $wallet->available);
        $this->assertSame(25, $wallet->lifetime_earned);

        // the earned coins can be spent on the next order
        $this->actAs($user);
        $this->addToCart($this->variantOf($this->shopProduct()), 5);
        $this->placeOrder(['address_id' => $this->address($user)->id, 'coins_to_redeem' => 25])->assertCreated()->assertJsonPath('data.totals.coins_redeemed', 25);
    }

    public function test_cancelling_as_admin_needs_a_reason_and_restores_stock_and_coins(): void
    {
        $user = $this->customer();
        $this->giveCoins($user, 100);
        $order = $this->orderFor($user, 4, [], 60);
        $variant = $order->items()->value('variant_id');
        $this->assertSame(46, $this->stockOf($variant)->stock_qty);
        $admin = $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');

        $this->setStatus($order, 'cancelled')->assertStatus(422)->assertJsonValidationErrors('reason');
        $res = $this->setStatus($order, 'cancelled', ['reason' => 'Customer called to cancel'])->assertOk();

        $res->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Customer called to cancel')->assertJsonPath('data.cancelled_by', 'admin')
            ->assertJsonPath('data.payment.status', 'cancelled');
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertSame(100, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));   // the 60 coins are back
        $this->assertSame('Cancelled: Customer called to cancel', collect($res->json('data.timeline'))->last()['note']);
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'cancel', 'qty_change' => 4, 'admin_id' => $admin->id]);

        // nothing more can happen to a cancelled order, and no coins are earned from it
        $this->setStatus($order, 'confirmed')->assertStatus(422);
        $this->assertDatabaseMissing('reward_transactions', ['user_id' => $user->id, 'type' => 'earn']);
    }

    public function test_a_shipped_or_delivered_order_cannot_be_cancelled(): void
    {
        $order = $this->orderFor($this->customer());
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');
        $this->setStatus($order, 'shipped', ['courier' => 'TCS', 'awb_number' => 'A1']);

        $this->setStatus($order, 'cancelled', ['reason' => 'Too late'])->assertStatus(422)->assertJsonPath('message', 'This order is shipped and can no longer be cancelled.');
        $this->setStatus($order, 'delivered')->assertOk();
        $this->setStatus($order, 'cancelled', ['reason' => 'Too late'])->assertStatus(422);
    }

    public function test_delivering_twice_cannot_credit_the_coins_twice(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user, 2);
        $this->deliver($order);

        $this->setStatus($order, 'delivered')->assertStatus(422);
        $this->assertSame(1, RewardTransaction::where('type', 'earn')->count());
        $this->assertSame(10, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));
    }

    public function test_the_customer_sees_the_admin_progress_on_their_own_order(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user);
        $this->asAdmin();
        $this->setStatus($order, 'confirmed');
        $this->setStatus($order, 'packed');
        $this->setStatus($order, 'shipped', ['courier' => 'Leopards', 'awb_number' => 'LEO-1', 'tracking_url' => 'https://track.example.test/1']);

        $this->actAs($user);
        $res = $this->getJson(self::STORE."/orders/{$order->order_number}")->assertOk();
        $res->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking.courier', 'Leopards')->assertJsonPath('data.tracking.awb_number', 'LEO-1')
            ->assertJsonPath('data.tracking.tracking_url', 'https://track.example.test/1');
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped'], collect($res->json('data.timeline'))->pluck('status')->all());
    }

    // ------------------------------------------------------------------ note

    public function test_the_admin_can_keep_an_internal_note_the_customer_never_sees(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user);
        $this->asAdmin();

        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'Fragile - double pack'])->assertOk()->assertJsonPath('data.admin_note', 'Fragile - double pack');
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertJsonPath('data.admin_note', 'Fragile - double pack');
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => str_repeat('x', 2001)])->assertStatus(422);
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => ''])->assertOk()->assertJsonPath('data.admin_note', null);

        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'secret'])->assertOk();
        $this->actAs($user);
        $this->assertStringNotContainsString('secret', $this->getJson(self::STORE."/orders/{$order->order_number}")->getContent());
    }

    // ------------------------------------------------------------------ dashboard

    public function test_the_dashboard_shows_todays_orders_revenue_pending_and_low_stock(): void
    {
        $user = $this->customer();
        $one = $this->orderFor($user, 2);   // 2 x 160 + 300 = 620
        $two = $this->orderFor($user, 1);   // 160 + 300 = 460
        $three = $this->orderFor($user, 1, ['stock' => 7]);   // 460, leaves 6; threshold 5 -> not low
        $this->asAdmin();
        $this->setStatus($two, 'confirmed');
        $this->setStatus($three, 'cancelled', ['reason' => 'Test']);
        DB::table('product_variants')->where('id', $one->items()->value('variant_id'))->update(['stock_qty' => 4]);   // running low

        $o = $this->getJson(self::ADMIN.'/dashboard')->assertOk()->json('orders');

        $this->assertSame(2, $o['today']);                  // the cancelled one does not count
        $this->assertEquals(1080, $o['revenue_today']);     // 620 + 460
        $this->assertSame(1, $o['pending']);
        $this->assertGreaterThanOrEqual(1, $o['low_stock']);
    }
}

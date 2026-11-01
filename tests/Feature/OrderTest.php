<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Order\Models\Order;
use App\Domain\Reward\Models\RewardTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    /** A logged-in customer with a default address and `$qty` units of a new product in the Standard cart. @return array{0: User, 1: int, 2: int} user, variant id, address id */
    private function shopper(int $qty = 5, array $variant = [], array $product = [], string $email = 'buyer@example.test', int $coins = 500): array
    {
        $user = $this->customer($email);
        $address = $this->address($user);
        if ($coins) {
            $this->giveCoins($user, $coins);
        }
        $variantId = $this->variantOf($this->shopProduct($variant, $product));
        $this->actAs($user);
        $this->addToCart($variantId, $qty)->assertCreated();

        return [$user, $variantId, $address->id];
    }

    // ------------------------------------------------------------------ happy path

    public function test_place_a_cod_order_end_to_end(): void
    {
        [$user, $variant, $address] = $this->shopper(5);   // 5 x 152 (bulk) = 760, delivery 300
        $before = $this->stockOf($variant)->stock_qty;

        $res = $this->placeOrder(['address_id' => $address, 'payment_method' => 'cod', 'coins_to_redeem' => 100, 'customer_note' => 'Call before delivery'])->assertCreated();
        $d = $res->json('data');

        $this->assertMatchesRegularExpression('/^DK-'.now()->year.'-\d{6}$/', $d['order_number']);
        $this->assertSame('pending', $d['status']);
        $this->assertSame('cod', $d['payment_method']);
        $this->assertSame('Cash on delivery', $d['payment_method_label']);
        $this->assertSame('cod_pending', $d['payment_status']);
        $this->assertTrue($d['can_cancel']);
        $this->assertSame(5, $d['items_count']);
        $this->assertSame('Call before delivery', $d['customer_note']);
        $this->assertSame(now()->addDays(5)->toDateString(), $d['estimated_delivery_date']);

        // price breakdown: 1000 at MRP - 200 product discount - 40 bulk offer = 760; - 100 coins + 300 delivery = 960
        $t = $d['totals'];
        $this->assertEquals(1000, $t['items_total']);
        $this->assertEquals(200, $t['product_discount']);
        $this->assertEquals(40, $t['bulk_discount']);
        $this->assertEquals(760, $t['subtotal']);
        $this->assertSame(100, $t['coins_redeemed']);
        $this->assertEquals(100, $t['coins_discount']);
        $this->assertEquals(300, $t['delivery_fee']);
        $this->assertEquals(960, $t['grand_total']);
        $this->assertEquals(round(760 * 18 / 118, 2), $t['tax_included']);
        $this->assertSame(25, $t['coins_to_earn']);
        $this->assertSame('Inclusive of all taxes', $t['tax_note']);

        // the line is a snapshot
        $item = $d['items'][0];
        $product = DB::table('product_variants')->where('id', $variant)->first();
        $this->assertSame($product->sku, $item['sku']);
        $this->assertSame($product->name, $item['option_name']);
        $this->assertSame('Test Brand', $item['brand']);
        $this->assertSame(5, $item['qty']);
        $this->assertEquals(200, $item['mrp']);
        $this->assertEquals(160, $item['price']);
        $this->assertEquals(152, $item['unit_price']);
        $this->assertSame(5, $item['bulk_applied']['min_qty']);
        $this->assertEquals(40, $item['bulk_discount']);
        $this->assertEquals(760, $item['line_total']);
        $this->assertEquals(18, $item['tax_rate']);
        $this->assertSame(25, $item['coins_earned']);

        // addresses are snapshots, billing = shipping by default
        $this->assertSame('Smile Clinic, Shop 4', $d['shipping_address']['line1']);
        $this->assertSame('03111234567', $d['shipping_address']['alt_phone']);
        $this->assertSame('clinic', $d['shipping_address']['type']);
        $this->assertSame($d['shipping_address'], $d['billing_address']);

        // timeline
        $this->assertSame(['pending'], collect($d['timeline'])->pluck('status')->all());

        // stock: deducted at once, with a movement
        $this->assertSame($before - 5, $this->stockOf($variant)->stock_qty);
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'sale', 'qty_change' => -5, 'balance_after' => $before - 5, 'reference_type' => 'order', 'reference_id' => $d['id']]);

        // coins: 100 debited now, nothing earned yet
        $wallet = DB::table('reward_wallets')->where('user_id', $user->id)->first();
        $this->assertSame(400, $wallet->available);
        $this->assertSame(100, $wallet->lifetime_redeemed);
        $this->assertDatabaseHas('reward_transactions', ['user_id' => $user->id, 'type' => 'redeem', 'coins' => -100, 'source_type' => 'order', 'source_id' => $d['id']]);
        $this->assertDatabaseMissing('reward_transactions', ['user_id' => $user->id, 'type' => 'earn']);

        // payment row and the emptied cart
        $this->assertDatabaseHas('payments', ['payable_type' => 'order', 'payable_id' => $d['id'], 'method' => 'cod', 'status' => 'cod_pending', 'amount' => 960, 'currency' => 'PKR']);
        $this->assertSame(0, $this->cart()->json('data.lines_count'));
        $this->assertSame(0, $this->cart()->json('data.summary.coins.requested'));

        // the order row keeps the customer as it was
        $this->assertDatabaseHas('orders', ['id' => $d['id'], 'user_id' => $user->id, 'customer_name' => 'Asha Khan', 'customer_email' => 'buyer@example.test', 'items_count' => 5]);
    }

    public function test_the_order_keeps_the_address_as_it_was_when_the_customer_edits_it_later(): void
    {
        [, , $address] = $this->shopper(1);
        $number = $this->placeOrder(['address_id' => $address])->assertCreated()->json('data.order_number');

        $this->putJson(self::STORE."/addresses/$address", ['city' => 'Karachi', 'line1' => 'Moved'])->assertOk();

        $this->getJson(self::STORE."/orders/$number")->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Lahore')
            ->assertJsonPath('data.shipping_address.line1', 'Smile Clinic, Shop 4');
    }

    public function test_an_inline_address_is_saved_to_the_address_book_unless_told_otherwise(): void
    {
        [$user] = $this->shopper(1);
        $inline = ['name' => 'Dr. New', 'phone' => '03211234567', 'line1' => 'New Clinic', 'area' => 'Sector F-7', 'city' => 'Islamabad', 'state' => 'ICT', 'postcode' => '44000', 'type' => 'home', 'gst_number' => '27ABCDE1234F1Z5'];

        $res = $this->placeOrder(['address' => $inline])->assertCreated();
        $res->assertJsonPath('data.shipping_address.city', 'Islamabad')->assertJsonPath('data.shipping_address.gst_number', '27ABCDE1234F1Z5');
        $this->assertDatabaseHas('user_addresses', ['user_id' => $user->id, 'city' => 'Islamabad']);
        $this->assertDatabaseHas('orders', ['id' => $res->json('data.id'), 'customer_gst_number' => '27ABCDE1234F1Z5']);

        $this->addToCart($this->variantOf($this->shopProduct()), 1);
        $this->placeOrder(['address' => ['city' => 'Multan'] + $inline, 'save_address' => false])->assertCreated()->assertJsonPath('data.shipping_address.city', 'Multan');
        $this->assertDatabaseMissing('user_addresses', ['city' => 'Multan']);
    }

    public function test_the_billing_address_can_differ_from_the_shipping_address(): void
    {
        [$user, , $address] = $this->shopper(1);
        $billing = $this->address($user, ['name' => 'Accounts Dept', 'city' => 'Karachi', 'gst_number' => '27ABCDE1234F1Z5'])->id;

        $this->placeOrder(['address_id' => $address, 'billing_address_id' => $billing])->assertCreated()
            ->assertJsonPath('data.shipping_address.city', 'Lahore')
            ->assertJsonPath('data.billing_address.city', 'Karachi')
            ->assertJsonPath('data.billing_address.name', 'Accounts Dept');
    }

    public function test_orders_on_instadent_use_the_instadent_stock_and_cart(): void
    {
        $user = $this->customer();
        $address = $this->address($user)->id;
        $variant = $this->variantOf($this->shopProduct());
        $this->actAs($user);
        $this->addToCart($variant, 2, 'instadent');
        $this->addToCart($variant, 1, 'standard');

        $d = $this->placeOrder(['address_id' => $address], 'instadent')->assertCreated()->json('data');

        $this->assertSame('instadent', $d['channel']);
        $this->assertSame(now()->addDays(3)->toDateString(), $d['estimated_delivery_date']);
        $this->assertSame(3, $this->stockOf($variant, 'instadent')->stock_qty);   // 5 - 2
        $this->assertSame(50, $this->stockOf($variant, 'standard')->stock_qty);   // untouched
        $this->assertSame(1, $this->cart('standard')->json('data.items.0.qty'));   // the Standard cart is still there
    }

    public function test_order_numbers_are_unique_and_readable(): void
    {
        [, , $address] = $this->shopper(1);
        $a = $this->placeOrder(['address_id' => $address])->json('data.order_number');
        $this->addToCart($this->variantOf($this->shopProduct()), 1);
        $b = $this->placeOrder(['address_id' => $address])->json('data.order_number');

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^DK-\d{4}-\d{6}$/', $a);
        $this->assertMatchesRegularExpression('/^DK-\d{4}-\d{6}$/', $b);
    }

    public function test_selling_the_last_unit_marks_the_product_out_of_stock_and_logs_a_low_stock_alert(): void
    {
        [, $variant, $address] = $this->shopper(3, ['stock' => ['standard' => 3, 'instadent' => 0]], coins: 0);

        $this->placeOrder(['address_id' => $address])->assertCreated();

        $this->assertSame(0, $this->stockOf($variant)->stock_qty);
        $this->assertSame(0, DB::table('products')->whereIn('id', DB::table('product_variants')->where('id', $variant)->select('product_id'))->value('is_in_stock'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.low_stock', 'subject_type' => 'ProductVariant', 'subject_id' => $variant]);
    }

    // ------------------------------------------------------------------ coins

    public function test_coins_to_redeem_are_clamped_not_trusted(): void
    {
        [$user, , $address] = $this->shopper(5, coins: 80);   // wallet 80

        $d = $this->placeOrder(['address_id' => $address, 'coins_to_redeem' => 99999])->assertCreated()->json('data');

        $this->assertSame(80, $d['totals']['coins_redeemed']);
        $this->assertEquals(80, $d['totals']['coins_discount']);
        $this->assertEquals(760 - 80 + 300, $d['totals']['grand_total']);
        $this->assertSame(0, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));
    }

    public function test_the_coins_saved_on_the_cart_are_used_when_checkout_does_not_send_any(): void
    {
        [, , $address] = $this->shopper(5, coins: 200);
        $this->putJson(self::STORE.'/cart/coins', ['coins_to_redeem' => 60])->assertOk();

        $this->placeOrder(['address_id' => $address])->assertCreated()->assertJsonPath('data.totals.coins_redeemed', 60);
    }

    public function test_coins_are_spent_earliest_expiry_first(): void
    {
        [$user, , $address] = $this->shopper(5, coins: 0);
        $this->giveCoins($user, 50);
        $this->giveCoins($user, 50);
        $later = RewardTransaction::where('user_id', $user->id)->orderByDesc('id')->first();
        $later->update(['expires_at' => now()->addDays(300)]);   // the first batch expires at day 365, this one earlier

        $this->placeOrder(['address_id' => $address, 'coins_to_redeem' => 60])->assertCreated();

        $this->assertSame(0, RewardTransaction::find($later->id)->remaining);      // consumed first (expires first)
        $this->assertSame(40, RewardTransaction::where('user_id', $user->id)->where('type', 'adjust')->orderBy('id')->first()->remaining);
        $this->assertSame(2, DB::table('reward_redemptions')->count());
    }

    public function test_expired_coins_cannot_be_spent(): void
    {
        [$user, , $address] = $this->shopper(5, coins: 100);
        RewardTransaction::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $this->placeOrder(['address_id' => $address, 'coins_to_redeem' => 50])->assertCreated()->assertJsonPath('data.totals.coins_redeemed', 0);
    }

    // ------------------------------------------------------------------ refusals

    public function test_checkout_needs_a_customer_login(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->actAs(null);
        $this->addToCart($variant, 1);

        $this->placeOrder(['address_id' => 1])->assertStatus(401);
        $this->actingAsAdmin();
        $this->placeOrder(['address_id' => 1])->assertStatus(401);
    }

    public function test_an_empty_cart_cannot_be_ordered(): void
    {
        $user = $this->customer();
        $address = $this->address($user)->id;
        $this->actAs($user);

        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cart_empty')->assertJsonPath('message', 'Your cart is empty.');
    }

    public function test_an_address_is_required_and_must_belong_to_the_customer(): void
    {
        $this->shopper(1);
        $this->placeOrder([])->assertStatus(422)->assertJsonValidationErrors(['address_id', 'address']);

        $other = $this->address($this->customer('other@example.test'))->id;
        $this->placeOrder(['address_id' => $other])->assertStatus(422)->assertJsonPath('code', 'address_not_found');
        $this->placeOrder(['address_id' => 999999])->assertStatus(422)->assertJsonPath('code', 'address_not_found');
        $this->placeOrder(['address_id' => $other, 'billing_address_id' => $other])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $this->cart()->json('data.lines_count'));   // the cart survives a failed checkout
    }

    public function test_an_inline_address_is_validated(): void
    {
        $this->shopper(1);

        $this->placeOrder(['address' => ['name' => 'X']])->assertStatus(422)->assertJsonValidationErrors(['address.phone', 'address.line1', 'address.area', 'address.city', 'address.postcode']);
        $this->placeOrder(['address' => ['name' => 'X', 'phone' => '0300123456', 'line1' => 'a', 'area' => 'b', 'city' => 'c', 'state' => 'd', 'postcode' => '1', 'gst_number' => 'bad']])
            ->assertStatus(422)->assertJsonValidationErrors(['address.postcode', 'address.gst_number']);
    }

    public function test_only_cash_on_delivery_exists_for_now(): void
    {
        [, , $address] = $this->shopper(1);

        $this->placeOrder(['address_id' => $address, 'payment_method' => 'card'])->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->placeOrder(['address_id' => $address, 'payment_method' => 'cod'])->assertCreated();
    }

    public function test_cod_has_an_amount_limit_and_can_be_switched_off_per_option_and_per_postcode(): void
    {
        [, , $address] = $this->shopper(5);   // grand total 1060

        $this->setSetting('cod_max_amount', 1000);
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed')
            ->assertJsonPath('message', 'Cash on delivery is available up to Rs 1,000 per order. Please reduce your order.');
        $this->setSetting('cod_max_amount', 0);   // 0 = no limit

        DB::table('pincodes')->insert(['pincode' => '54660', 'city' => 'Lahore', 'state' => 'Punjab', 'is_serviceable' => true, 'cod_available' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed');
        DB::table('pincodes')->update(['is_serviceable' => false]);
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'pincode_not_serviceable');
        DB::table('pincodes')->delete();

        DB::table('product_variants')->where('id', $this->cart()->json('data.items.0.variant_id'))->update(['is_cod_available' => false]);
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed');
        DB::table('product_variants')->update(['is_cod_available' => null]);

        $this->placeOrder(['address_id' => $address])->assertCreated();
    }

    public function test_there_is_a_minimum_order_amount(): void
    {
        [, , $address] = $this->shopper(1);   // subtotal 160
        $this->setSetting('min_order_amount', 500);

        $cart = $this->cart();
        $cart->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.issues.0.code', 'below_min_order');
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'below_min_order')
            ->assertJsonPath('message', 'The minimum order amount is Rs 500. Add items worth Rs 340 more.');

        $this->setSetting('min_order_amount', 160);
        $this->placeOrder(['address_id' => $address])->assertCreated();
    }

    public function test_a_stale_cart_is_refused_with_the_lines_that_need_attention(): void
    {
        [, $variant, $address] = $this->shopper(6);
        $this->stockOf($variant)->update(['stock_qty' => 4]);

        $res = $this->placeOrder(['address_id' => $address])->assertStatus(422);
        $res->assertJsonPath('code', 'cart_invalid')->assertJsonPath('lines.0.variant_id', $variant)->assertJsonPath('lines.0.code', 'insufficient_stock');

        $this->assertSame(0, Order::count());
        $this->assertSame(4, $this->stockOf($variant)->stock_qty);
    }

    public function test_the_total_the_customer_saw_must_still_be_true(): void
    {
        [$user, $variant, $address] = $this->shopper(1);
        $seen = $this->cart()->json('data.summary.grand_total');   // 160 + 300

        DB::table('product_variants')->where('id', $variant)->update(['selling_price' => 170]);

        $this->placeOrder(['address_id' => $address, 'expected_total' => $seen])->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('current_total', 470);
        $this->assertSame(0, Order::count());

        $this->placeOrder(['address_id' => $address, 'expected_total' => 470])->assertCreated()->assertJsonPath('data.totals.grand_total', 470);
    }

    public function test_the_price_comes_from_the_server_never_from_the_client(): void
    {
        [, , $address] = $this->shopper(1);

        $this->placeOrder(['address_id' => $address, 'unit_price' => 1, 'grand_total' => 1, 'items' => [['variant_id' => 1, 'price' => 1]]])
            ->assertCreated()->assertJsonPath('data.totals.grand_total', 460);
    }

    // ------------------------------------------------------------------ double submit and overselling

    public function test_the_same_idempotency_key_returns_the_same_order(): void
    {
        [, $variant, $address] = $this->shopper(2, coins: 0);
        $key = 'order-key-0001';

        $first = $this->placeOrder(['address_id' => $address], 'standard', ['Idempotency-Key' => $key])->assertCreated();
        $second = $this->placeOrder(['address_id' => $address], 'standard', ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame(1, Order::count());
        $this->assertSame(48, $this->stockOf($variant)->stock_qty);   // deducted once
    }

    public function test_a_double_tap_without_a_key_cannot_make_two_orders(): void
    {
        [, $variant, $address] = $this->shopper(2, coins: 0);

        $this->placeOrder(['address_id' => $address])->assertCreated();
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cart_empty');

        $this->assertSame(1, Order::count());
        $this->assertSame(48, $this->stockOf($variant)->stock_qty);
    }

    public function test_a_bad_idempotency_key_is_refused(): void
    {
        [, , $address] = $this->shopper(1);

        $this->placeOrder(['address_id' => $address], 'standard', ['Idempotency-Key' => 'x'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_invalid');
    }

    public function test_the_last_unit_can_only_be_sold_once(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => ['standard' => 1, 'instadent' => 0]]));
        $alice = $this->customer('alice@example.test');
        $bob = $this->customer('bob@example.test');
        $aliceAddress = $this->address($alice)->id;
        $bobAddress = $this->address($bob)->id;

        $this->actAs($alice);
        $this->addToCart($variant, 1)->assertCreated();
        $this->actAs($bob);
        $this->addToCart($variant, 1)->assertCreated();   // both carts hold the last unit

        $this->actAs($alice);
        $this->placeOrder(['address_id' => $aliceAddress])->assertCreated();
        $this->actAs($bob);
        $this->placeOrder(['address_id' => $bobAddress])->assertStatus(422)->assertJsonPath('code', 'cart_invalid')->assertJsonPath('lines.0.code', 'out_of_stock');

        $this->assertSame(0, $this->stockOf($variant)->stock_qty);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $this->cart()->json('data.lines_count'));   // Bob keeps his cart and sees the warning
    }

    public function test_stock_never_goes_below_zero_however_many_customers_check_out(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => ['standard' => 5, 'instadent' => 0]], ['max_order_qty' => 5]));
        $customers = collect(range(1, 6))->map(function ($i) use ($variant) {
            $user = $this->customer("c$i@example.test");
            $address = $this->address($user)->id;
            $this->actAs($user);
            $this->addToCart($variant, 2)->assertCreated();

            return [$user, $address];
        });

        $placed = 0;
        foreach ($customers as [$user, $address]) {
            $this->actAs($user);
            $placed += $this->placeOrder(['address_id' => $address])->status() === 201 ? 1 : 0;
        }

        $this->assertSame(2, $placed);                                        // 5 units -> only two customers can have 2
        $this->assertSame(1, $this->stockOf($variant)->stock_qty);            // 5 - 4, never negative
        $this->assertSame(4, (int) DB::table('order_items')->sum('qty'));
        $this->assertSame(-4, (int) DB::table('inventory_movements')->where('type', 'sale')->sum('qty_change'));
    }

    public function test_checkout_locks_the_stock_rows_before_it_reads_them(): void
    {
        [, , $address] = $this->shopper(1, coins: 0);

        DB::enableQueryLog();
        $this->placeOrder(['address_id' => $address])->assertCreated();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $lockAt = $queries->search(fn ($q) => str_contains($q, 'variant_channel_stock') && str_contains(strtolower($q), 'for update'));
        $decrementAt = $queries->search(fn ($q) => str_starts_with($q, 'update `variant_channel_stock`'));
        $cartLock = $queries->search(fn ($q) => str_contains($q, 'from `carts`') && str_contains(strtolower($q), 'for update'));

        $this->assertNotFalse($lockAt, 'the stock rows must be read with FOR UPDATE');
        $this->assertNotFalse($decrementAt);
        $this->assertNotFalse($cartLock, 'the cart row is locked so a double submit has to wait');
        $this->assertLessThan($decrementAt, $lockAt);
        $this->assertLessThan($lockAt, $cartLock);
    }

    // ------------------------------------------------------------------ cancel

    public function test_cancelling_puts_back_the_stock_and_the_coins_and_keeps_the_history(): void
    {
        [$user, $variant, $address] = $this->shopper(5, coins: 300);
        $d = $this->placeOrder(['address_id' => $address, 'coins_to_redeem' => 100])->assertCreated()->json('data');
        $this->assertSame(45, $this->stockOf($variant)->stock_qty);
        $this->assertSame(200, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));

        $res = $this->postJson(self::STORE."/orders/{$d['order_number']}/cancel", ['reason' => 'Ordered by mistake'])->assertOk();

        $res->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false)->assertJsonPath('data.cancel_reason', 'Ordered by mistake')
            ->assertJsonPath('data.payment_status', 'cancelled');
        $this->assertSame(['pending', 'cancelled'], collect($res->json('data.timeline'))->pluck('status')->all());   // the history stays
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'cancel', 'qty_change' => 5, 'balance_after' => 50, 'reference_id' => $d['id']]);
        $this->assertSame(300, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));   // 100 coins are back
        $this->assertDatabaseHas('reward_transactions', ['user_id' => $user->id, 'type' => 'reverse', 'coins' => 100, 'remaining' => 100, 'status' => 'available']);
        $this->assertDatabaseHas('payments', ['payable_id' => $d['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('order_items', ['order_id' => $d['id'], 'status' => 'cancelled', 'cancelled_qty' => 5]);
        $this->assertDatabaseHas('orders', ['id' => $d['id'], 'cancelled_by' => 'customer']);

        // the refunded coins can be spent again; and cancelling twice does nothing
        $this->postJson(self::STORE."/orders/{$d['order_number']}/cancel")->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertSame(1, RewardTransaction::where('type', 'reverse')->count());
    }

    public function test_a_shipped_order_cannot_be_cancelled_by_the_customer(): void
    {
        [, , $address] = $this->shopper(1);
        $number = $this->placeOrder(['address_id' => $address])->json('data.order_number');
        Order::where('order_number', $number)->update(['status' => 'shipped']);

        $this->postJson(self::STORE."/orders/$number/cancel")->assertStatus(422)->assertJsonPath('code', 'invalid_status_change');
        $this->getJson(self::STORE."/orders/$number")->assertJsonPath('data.can_cancel', false);
    }

    public function test_a_customer_cannot_cancel_view_or_reorder_another_customers_order(): void
    {
        [, , $address] = $this->shopper(1);
        $number = $this->placeOrder(['address_id' => $address])->json('data.order_number');
        $mallory = $this->customer('mallory@example.test');

        $this->actAs($mallory);
        $this->getJson(self::STORE."/orders/$number")->assertNotFound();
        $this->postJson(self::STORE."/orders/$number/cancel")->assertNotFound();
        $this->postJson(self::STORE."/orders/$number/reorder")->assertNotFound();
        $this->assertSame([], $this->getJson(self::STORE.'/orders')->json('data'));
        $this->assertSame('pending', DB::table('orders')->where('order_number', $number)->value('status'));
    }

    // ------------------------------------------------------------------ my orders

    public function test_my_orders_are_paginated_newest_first_and_filterable(): void
    {
        [$user, , $address] = $this->shopper(1);
        $numbers = [];
        foreach (range(1, 3) as $i) {
            $this->addToCart($this->variantOf($this->shopProduct()), 1);
            $numbers[] = $this->placeOrder(['address_id' => $address])->assertCreated()->json('data.order_number');
        }
        $this->postJson(self::STORE."/orders/{$numbers[0]}/cancel")->assertOk();

        $res = $this->getJson(self::STORE.'/orders?per_page=2')->assertOk();
        $this->assertSame([$numbers[2], $numbers[1]], collect($res->json('data'))->pluck('order_number')->all());   // newest first
        $res->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
        $this->assertSame(['id', 'order_number', 'status', 'status_label', 'can_cancel', 'channel', 'placed_at', 'estimated_delivery_date', 'items_count', 'grand_total', 'payment_method', 'payment_method_label', 'payment_status', 'preview'], array_keys($res->json('data.0')));

        $this->assertSame([$numbers[0]], collect($this->getJson(self::STORE.'/orders?status=cancelled')->json('data'))->pluck('order_number')->all());
        $this->getJson(self::STORE.'/orders?per_page=101')->assertStatus(422);
        $this->getJson(self::STORE.'/orders?status=nonsense')->assertStatus(422);
    }

    public function test_the_order_list_costs_a_fixed_number_of_queries(): void
    {
        [, , $address] = $this->shopper(1);
        $this->placeOrder(['address_id' => $address])->assertCreated();
        DB::enableQueryLog();
        $this->getJson(self::STORE.'/orders')->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            $this->addToCart($this->variantOf($this->shopProduct()), 1);
            $this->placeOrder(['address_id' => $address])->assertCreated();
        }
        DB::flushQueryLog();
        $res = $this->getJson(self::STORE.'/orders')->assertOk();

        $this->assertCount(5, $res->json('data'));
        $this->assertSame($one, count(DB::getQueryLog()));
    }

    public function test_the_order_detail_has_everything_the_customer_needs(): void
    {
        [, , $address] = $this->shopper(2);
        $number = $this->placeOrder(['address_id' => $address])->json('data.order_number');

        $d = $this->getJson(self::STORE."/orders/$number")->assertOk()->json('data');

        $this->assertSame(['id', 'order_number', 'status', 'status_label', 'can_cancel', 'channel', 'placed_at', 'estimated_delivery_date', 'items_count', 'grand_total', 'payment_method', 'payment_method_label',
            'payment_status', 'customer_note', 'cancel_reason', 'items', 'shipping_address', 'billing_address', 'totals', 'tracking', 'timeline'], array_keys($d));
        $this->assertNull($d['tracking']);
        $this->assertCount(1, $d['items']);
    }

    // ------------------------------------------------------------------ reorder

    public function test_reorder_fills_the_cart_with_what_is_still_sold(): void
    {
        [, $variant, $address] = $this->shopper(6, ['stock' => ['standard' => 8, 'instadent' => 0]]);
        $gone = $this->shopProduct();
        $this->addToCart($this->variantOf($gone), 2);
        $number = $this->placeOrder(['address_id' => $address])->json('data.order_number');
        $this->assertSame(0, $this->cart()->json('data.lines_count'));

        $this->stockOf($variant)->update(['stock_qty' => 3]);   // only 3 left of the 6 ordered
        $gone->update(['status' => 'inactive']);

        $res = $this->postJson(self::STORE."/orders/$number/reorder")->assertOk();

        $this->assertSame(1, $res->json('data.lines_count'));
        $this->assertSame(3, $res->json('data.items.0.qty'));             // cut down to the stock
        $this->assertSame(1, $res->json('reorder.added'));
        $this->assertSame('unavailable', DB::table('product_variants')->where('id', $res->json('reorder.skipped.0.variant_id'))->exists() ? 'unavailable' : '');
        $this->assertCount(1, $res->json('reorder.skipped'));
    }

    public function test_the_price_of_a_reordered_item_is_todays_price(): void
    {
        [, $variant, $address] = $this->shopper(1);
        $number = $this->placeOrder(['address_id' => $address])->json('data.order_number');
        DB::table('product_variants')->where('id', $variant)->update(['selling_price' => 175]);

        $this->postJson(self::STORE."/orders/$number/reorder")->assertOk()->assertJsonPath('data.items.0.unit_price', 175);
    }
}

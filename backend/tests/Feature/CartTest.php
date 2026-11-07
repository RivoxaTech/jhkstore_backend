<?php

namespace Tests\Feature;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Order\Models\Order;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The persistent server cart: a random token instead of a login, expiry, live re-checking, and the token in the quote and the order. */
class CartTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cart(string $method, string $path = '', array $body = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withHeaders($token ? ['X-Cart-Token' => $token] : [])->json($method, self::STORE.'/cart'.$path, $body);
    }

    private function newCart(): string
    {
        return $this->cart('GET')->assertOk()->json('data.token');
    }

    // ------------------------------------------------------------------ the token

    public function test_a_cart_without_a_token_creates_a_new_one_and_returns_the_token_twice(): void
    {
        $res = $this->cart('GET')->assertOk();

        $token = $res->json('data.token');
        $this->assertSame(40, strlen($token));
        $this->assertSame($token, $res->headers->get('X-Cart-Token'));
        $this->assertSame([], $res->json('data.items'));
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame(1, Cart::count());
        $this->assertNotNull($res->json('data.expires_at'));
        $this->assertSame(['name' => null, 'phone' => null, 'email' => null], $res->json('data.contact'));

        $again = $this->cart('GET', '', [], $token)->assertOk();
        $this->assertSame($token, $again->json('data.token'));
        $this->assertSame(1, Cart::count());                  // the same cart, not a new one
    }

    public function test_an_unknown_or_malformed_token_starts_a_new_cart(): void
    {
        foreach ([str_repeat('a', 40), 'short', str_repeat('z', 41), '../../etc/passwd'] as $token) {
            $res = $this->cart('GET', '', [], $token)->assertOk();
            $this->assertNotSame($token, $res->json('data.token'));
            $this->assertSame(40, strlen($res->json('data.token')));
        }
        $this->assertSame(4, Cart::count());
    }

    public function test_the_token_is_never_part_of_a_model_array_and_no_admin_endpoint_lists_carts(): void
    {
        $this->newCart();

        $this->assertArrayNotHasKey('token', Cart::first()->toArray());
        $this->actingAsAdmin('developer');
        foreach (['/carts', '/carts/1', '/abandoned-carts'] as $uri) {
            $this->getJson(self::ADMIN.$uri)->assertNotFound();
        }
    }

    // ------------------------------------------------------------------ lines

    public function test_lines_are_added_changed_and_removed_and_the_cart_is_priced_on_the_server(): void
    {
        $a = $this->variantOf($this->shopProduct());    // 160, bulk 5+ = 152
        $b = $this->variantOf($this->shopProduct());
        $token = $this->newCart();

        $res = $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 2], $token)->assertOk();
        $this->assertEquals(320, $res->json('data.summary.grand_total'));
        $this->assertSame($token, $res->json('data.token'));

        $res = $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 3], $token)->assertOk();                  // added on top: 5 -> the bulk price
        $this->assertSame(5, $res->json('data.items.0.qty'));
        $this->assertEquals(152, $res->json('data.items.0.unit_price'));
        $this->assertEquals(760, $res->json('data.summary.subtotal'));

        $this->cart('POST', '/items', ['variant_id' => $b, 'qty' => 1], $token)->assertOk();
        $res = $this->cart('PUT', "/items/$a", ['qty' => 1], $token)->assertOk();                                    // set, not added
        $this->assertSame([1, 1], array_column($res->json('data.items'), 'qty'));
        $this->assertEquals(320, $res->json('data.summary.grand_total'));
        $this->assertTrue($res->json('data.can_checkout'));

        $res = $this->cart('DELETE', "/items/$a", [], $token)->assertOk();
        $this->assertSame([$b], array_column($res->json('data.items'), 'variant_id'));
        $this->assertSame(1, CartItem::count());

        $res = $this->cart('DELETE', '', [], $token)->assertOk();
        $this->assertSame([], $res->json('data.items'));
        $this->assertSame(0, CartItem::count());
        $this->assertSame(1, Cart::count());                                                                           // the cart itself stays
    }

    public function test_the_cart_persists_between_requests(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 2], $token)->assertOk();

        $this->assertSame([2], array_column($this->cart('GET', '', [], $token)->json('data.items'), 'qty'));
        $this->assertSame(2, CartItem::where('variant_id', $variant)->value('qty'));
        $this->assertSame([], $this->cart('GET', '', [], $this->newCart())->json('data.items'));                     // another browser has its own cart
    }

    public function test_the_first_add_without_a_token_creates_the_cart(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $res = $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1])->assertOk();

        $this->assertSame(1, Cart::count());
        $this->assertSame(Cart::first()->token, $res->json('data.token'));
        $this->assertSame($res->json('data.token'), $res->headers->get('X-Cart-Token'));
    }

    public function test_the_limits_of_a_cart(): void
    {
        config(['shop.max_items_per_order' => 2, 'shop.max_qty_per_line' => 5]);
        [$a, $b, $c] = array_map(fn () => $this->variantOf($this->shopProduct()), range(1, 3));
        $token = $this->newCart();

        $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 6], $token)->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 3], $token)->assertOk();
        $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 3], $token)->assertStatus(422)->assertJsonPath('code', 'cart_limit');   // 3 + 3 > 5
        $this->cart('PUT', "/items/$a", ['qty' => 6], $token)->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->cart('POST', '/items', ['variant_id' => $b, 'qty' => 1], $token)->assertOk();
        $this->cart('POST', '/items', ['variant_id' => $c, 'qty' => 1], $token)->assertStatus(422)->assertJsonPath('code', 'cart_limit');   // a third line
        $this->cart('POST', '/items', ['variant_id' => $b, 'qty' => 1], $token)->assertOk();                         // more of an existing line is fine

        $this->cart('POST', '/items', ['variant_id' => $a, 'qty' => 0], $token)->assertStatus(422);
        $this->cart('POST', '/items', ['variant_id' => 'x', 'qty' => 1], $token)->assertStatus(422);
        $this->cart('POST', '/items', ['qty' => 1], $token)->assertStatus(422)->assertJsonValidationErrors('variant_id');
        $this->cart('PUT', "/items/$c", ['qty' => 1], $token)->assertStatus(404)->assertJsonPath('code', 'not_in_cart');
        $this->cart('PUT', "/items/$a", ['qty' => 2, 'variant_id' => $b], $token)->assertStatus(422);
        $this->assertSame(2, CartItem::count());
    }

    public function test_an_option_that_cannot_be_sold_cannot_be_added(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        $draft = $this->variantOf($this->shopProduct([], ['status' => 'draft']));
        $token = $this->newCart();

        $this->cart('POST', '/items', ['variant_id' => 999999, 'qty' => 1], $token)->assertStatus(422)->assertJsonPath('code', 'unavailable');
        $this->cart('POST', '/items', ['variant_id' => $draft, 'qty' => 1], $token)->assertStatus(422)->assertJsonPath('code', 'unavailable');
        ProductVariant::whereKey($variant)->update(['is_active' => false]);
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $token)->assertStatus(422)->assertJsonPath('code', 'unavailable');
        $this->assertSame(0, CartItem::count());
    }

    // ------------------------------------------------------------------ live re-checking

    public function test_prices_and_stock_are_checked_again_on_every_read_and_a_line_is_never_deleted_silently(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        $other = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 2], $token);
        $this->cart('POST', '/items', ['variant_id' => $other, 'qty' => 1], $token);
        $this->assertEquals(480, $this->cart('GET', '', [], $token)->json('data.summary.grand_total'));

        ProductVariant::whereKey($variant)->update(['selling_price' => 100]);                                          // the price changed
        $this->assertEquals(360, $this->cart('GET', '', [], $token)->json('data.summary.grand_total'));

        ProductVariant::whereKey($variant)->update(['stock_qty' => 1]);                                                // not enough stock any more
        $res = $this->cart('GET', '', [], $token);
        $this->assertSame('insufficient_stock', $res->json('data.items.0.warnings.0.code'));
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame('insufficient_stock', $res->json('data.issues.0.code'));

        ProductVariant::whereKey($variant)->update(['stock_qty' => 0]);                                                // sold out: the line stays, with a blocking warning
        $res = $this->cart('GET', '', [], $token);
        $this->assertCount(2, $res->json('data.items'));
        $this->assertSame('out_of_stock', $res->json('data.items.0.warnings.0.code'));
        $this->assertTrue($res->json('data.items.0.warnings.0.blocking'));
        $this->assertFalse($res->json('data.items.0.in_totals'));

        ProductVariant::whereKey($variant)->update(['is_active' => false]);                                            // switched off
        $this->assertSame('unavailable', $this->cart('GET', '', [], $token)->json('data.items.0.warnings.0.code'));

        DB::table('order_items')->where('variant_id', $variant)->delete();
        DB::table('product_variants')->where('id', $variant)->delete();                                                // deleted for good
        $res = $this->cart('GET', '', [], $token);
        $this->assertSame('unavailable', $res->json('data.issues.0.code'));
        $this->assertSame($variant, $res->json('data.issues.0.variant_id'));
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame(2, CartItem::count());                                                                       // still in the cart: the shopper decides
    }

    // ------------------------------------------------------------------ contact and coupon

    public function test_the_contact_is_saved_for_the_shop_to_follow_up(): void
    {
        $token = $this->newCart();

        $res = $this->cart('PUT', '/contact', ['name' => 'Alex Morgan', 'phone' => '+1 555 010 0100', 'email' => 'Alex@Example.TEST'], $token)->assertOk();
        $this->assertSame(['name' => 'Alex Morgan', 'phone' => '+15550100100', 'email' => 'alex@example.test'], $res->json('data.contact'));

        $res = $this->cart('PUT', '/contact', ['name' => 'Alex M.'], $token)->assertOk();                              // only what is sent changes
        $this->assertSame(['name' => 'Alex M.', 'phone' => '+15550100100', 'email' => 'alex@example.test'], $res->json('data.contact'));

        $res = $this->cart('PUT', '/contact', ['email' => null], $token)->assertOk();
        $this->assertNull($res->json('data.contact.email'));
        $this->cart('PUT', '/contact', ['phone' => 'call me'], $token)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->cart('PUT', '/contact', ['email' => 'not-an-email'], $token)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame('+15550100100', Cart::first()->customer_phone);
    }

    public function test_a_coupon_on_the_cart(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'SAVE10']);
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $token);

        $this->cart('PUT', '/coupon', ['code' => 'nope'], $token)->assertStatus(422)->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('coupon_error.code', 'not_found');
        $this->assertNull(Cart::first()->coupon_code);                                                                  // a refused code is not saved
        $this->cart('PUT', '/coupon', [], $token)->assertStatus(422)->assertJsonValidationErrors('code');

        $res = $this->cart('PUT', '/coupon', ['code' => 'save10'], $token)->assertOk();
        $this->assertSame('SAVE10', $res->json('data.summary.coupon_code'));
        $this->assertEquals(16, $res->json('data.summary.coupon_discount'));
        $this->assertEquals(144, $res->json('data.summary.grand_total'));
        $this->assertSame('SAVE10', Cart::first()->coupon_code);

        $coupon->update(['is_active' => false]);                                                                        // revalidated on every read
        $res = $this->cart('GET', '', [], $token);
        $this->assertSame('inactive', $res->json('data.summary.coupon_error.code'));
        $this->assertEquals(160, $res->json('data.summary.grand_total'));

        $coupon->update(['is_active' => true]);
        $res = $this->cart('DELETE', '/coupon', [], $token)->assertOk();
        $this->assertNull($res->json('data.summary.coupon_code'));
        $this->assertNull(Cart::first()->coupon_code);
    }

    public function test_the_delivery_fee_of_a_zone_can_be_asked_for_on_the_cart(): void
    {
        $this->setSetting('delivery_method', 'zones');
        $this->makeZone(['match_values' => ['Springfield'], 'fee' => 6]);
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $token);

        $this->assertTrue($this->cart('GET', '', [], $token)->json('data.summary.delivery_needs_address'));
        $res = $this->cart('GET', '?city=Springfield', [], $token);
        $this->assertEquals(6, $res->json('data.summary.delivery_fee'));
        $this->assertEquals(166, $res->json('data.summary.grand_total'));
    }

    // ------------------------------------------------------------------ expiry

    public function test_a_cart_expires_thirty_days_after_its_last_activity_and_every_call_renews_it(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->assertTrue(Cart::first()->expires_at->equalTo(Carbon::parse('2026-12-03 10:00:00')));
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $token);

        Carbon::setTestNow('2026-11-20 10:00:00');                                                                      // 17 days later: still there, and renewed
        $this->assertCount(1, $this->cart('GET', '', [], $token)->json('data.items'));
        $this->assertTrue(Cart::first()->last_activity_at->equalTo(Carbon::parse('2026-11-20 10:00:00')));
        $this->assertTrue(Cart::first()->expires_at->equalTo(Carbon::parse('2026-12-20 10:00:00')));

        Carbon::setTestNow('2026-12-19 10:00:00');                                                                      // 29 days after the last call
        $this->assertCount(1, $this->cart('GET', '', [], $token)->json('data.items'));

        Carbon::setTestNow('2027-01-19 10:00:01');                                                                      // 31 days without a call: gone
        $res = $this->cart('GET', '', [], $token)->assertOk();
        $this->assertNotSame($token, $res->json('data.token'));
        $this->assertSame([], $res->json('data.items'));
    }

    public function test_the_prune_command_removes_expired_carts_with_their_lines_and_keeps_the_rest(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        Carbon::setTestNow('2026-11-03 10:00:00');
        $old = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $old);
        Carbon::setTestNow('2026-12-10 10:00:00');                                                                      // 37 days later
        $fresh = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 2], $fresh);
        $this->assertSame(2, Cart::count());

        $this->artisan('shop:prune-carts')->expectsOutput('Deleted 1 expired cart(s).')->assertExitCode(0);

        $this->assertSame(1, Cart::count());
        $this->assertSame($fresh, Cart::first()->token);
        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->qty);
    }

    public function test_the_prune_command_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'shop:prune-carts'));

        $this->assertNotNull($event, 'shop:prune-carts must be scheduled');
        $this->assertSame('10 4 * * *', $event->expression);
    }

    // ------------------------------------------------------------------ the quote and the order

    public function test_the_quote_accepts_a_cart_token(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'SAVE10']);
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 5], $token);
        $this->cart('PUT', '/coupon', ['code' => 'SAVE10'], $token);

        $res = $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['cart_token' => $token])->assertOk();

        $this->assertEquals(152, $res->json('data.items.0.unit_price'));
        $this->assertEquals(76, $res->json('data.summary.coupon_discount'));               // the cart's coupon comes with it
        $this->assertTrue($res->json('data.can_checkout'));
        $this->assertSame(1, CartItem::count());                                           // a quote changes nothing

        $own = $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['cart_token' => $token, 'coupon_code' => 'OTHER'])->assertOk();
        $this->assertSame('not_found', $own->json('data.summary.coupon_error.code'));       // the request's own code wins

        $items = $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['cart_token' => $token, 'items' => [['variant_id' => $variant, 'qty' => 1]]])->assertOk();
        $this->assertSame(1, $items->json('data.items.0.qty'));                             // items win over the cart

        $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['cart_token' => str_repeat('a', 40)])->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.can_checkout', false);
        $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', [])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['cart_token' => 'short'])->assertStatus(422)->assertJsonValidationErrors('cart_token');
    }

    public function test_an_order_from_a_cart_token_empties_the_cart(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'SAVE10']);
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 2], $token);
        $this->cart('PUT', '/coupon', ['code' => 'SAVE10'], $token);
        $this->cart('PUT', '/contact', ['name' => 'Alex', 'phone' => '+1 555 010 0100'], $token);
        $body = ['customer' => $this->contact(), 'address' => $this->addressData(), 'cart_token' => $token];

        $res = $this->placeOrder($body)->assertCreated();

        $order = Order::where('order_number', $res->json('data.order_number'))->with('items')->first();
        $this->assertSame([$variant], $order->items->pluck('variant_id')->all());
        $this->assertSame(2, $order->items->first()->qty);
        $this->assertSame('SAVE10', $order->coupon_code);                                  // the cart's coupon was used
        $this->assertEquals(32, $order->coupon_discount);
        $this->assertSame(48, $this->stockOf($variant)->stock_qty);
        $this->assertSame(0, CartItem::count());                                           // emptied inside the order transaction
        $this->assertNull(Cart::first()->coupon_code);
        $this->assertSame('+15550100100', Cart::first()->customer_phone);                  // the contact stays
        $this->assertSame(1, $coupon->fresh()->used_count);

        $this->placeOrder($body)->assertStatus(422)->assertJsonPath('code', 'cart_invalid');   // the cart is empty now: no second order
        $this->assertSame(1, Order::count());
    }

    public function test_an_order_that_fails_leaves_the_cart_as_it_was(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 2], $token);
        ProductVariant::whereKey($variant)->update(['stock_qty' => 1]);

        $this->placeOrder(['customer' => $this->contact(), 'address' => $this->addressData(), 'cart_token' => $token])->assertStatus(422)->assertJsonPath('code', 'cart_invalid');

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->qty);
        $this->assertSame(0, Order::count());
    }

    public function test_an_order_needs_items_or_a_working_cart_token(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $base = ['customer' => $this->contact(), 'address' => $this->addressData()];

        $this->placeOrder($base)->assertStatus(422)->assertJsonValidationErrors('items');
        $this->placeOrder($base + ['cart_token' => str_repeat('b', 40)])->assertStatus(422)->assertJsonPath('code', 'cart_invalid');   // unknown token
        $empty = $this->newCart();
        $this->placeOrder($base + ['cart_token' => $empty])->assertStatus(422)->assertJsonPath('code', 'cart_invalid');                    // empty cart

        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 3], $token);
        $this->placeOrder($base + ['cart_token' => $token, 'items' => [['variant_id' => $variant, 'qty' => 1]]])->assertCreated()->assertJsonPath('data.items_count', 1);   // items win
        $this->assertSame(3, CartItem::first()->qty);                                       // and the cart is left alone

        Carbon::setTestNow(now()->addDays(31));
        $this->placeOrder($base + ['cart_token' => $token])->assertStatus(422)->assertJsonPath('code', 'cart_invalid');                     // expired
    }

    public function test_a_cart_order_can_be_retried_with_its_idempotency_key(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->newCart();
        $this->cart('POST', '/items', ['variant_id' => $variant, 'qty' => 1], $token);
        $body = ['customer' => $this->contact(['phone' => '+1 555 010 4242']), 'address' => $this->addressData(), 'cart_token' => $token];

        $first = $this->placeOrder($body, ['Idempotency-Key' => 'cart-retry-0001'])->assertCreated()->json('data.order_number');
        $this->placeOrder($body, ['Idempotency-Key' => 'cart-retry-0001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.order_number', $first);

        $this->assertSame(1, Order::count());
    }

    public function test_every_cart_call_is_rate_limited(): void
    {
        config(['shop.rate.cart_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->cart('GET')->assertOk();
        }
        $this->cart('GET')->assertStatus(429)->assertHeader('Retry-After');
        $this->cart('POST', '/items', ['variant_id' => 1, 'qty' => 1])->assertStatus(429);
        $this->assertSame(3, Cart::count());
    }
}

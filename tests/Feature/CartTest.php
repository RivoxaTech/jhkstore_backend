<?php

namespace Tests\Feature;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Enums\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CartTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    // ------------------------------------------------------------------ guest cart

    public function test_a_guest_can_add_to_the_cart_and_gets_a_cart_token(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $res = $this->addToCart($variant, 2)->assertCreated();
        $token = $res->json('data.token');

        $this->assertSame(40, strlen($token));
        $res->assertHeader('X-Cart-Token', $token)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.lines_count', 1)
            ->assertJsonPath('data.items.0.variant_id', $variant)
            ->assertJsonPath('data.items.0.qty', 2);

        // the same guest reads it back with the token; another guest (no token) sees an empty cart
        $this->cart(['X-Cart-Token' => $token])->assertOk()->assertJsonPath('data.items_count', 2);
        $this->cart()->assertOk()->assertJsonPath('data.items_count', 0)->assertJsonPath('data.token', null);
    }

    public function test_an_unknown_cart_token_is_an_empty_cart_and_a_new_one_is_created_on_add(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $fake = str_repeat('a', 40);

        $this->cart(['X-Cart-Token' => $fake])->assertOk()->assertJsonPath('data.items_count', 0);
        $new = $this->addToCart($variant, 1, ['X-Cart-Token' => $fake])->assertCreated()->json('data.token');

        $this->assertNotSame($fake, $new, 'the server never lets the client choose a token');
    }

    // ------------------------------------------------------------------ the maths

    public function test_cart_math_with_bulk_prices_delivery_and_coins_to_earn(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        // 5 units: MRP 5 x 200 = 1000, option price 5 x 160 = 800 (product discount 200), bulk price 152 -> 5 x 8 = 40 off
        $res = $this->addToCart($variant, 5)->assertCreated();
        $line = $res->json('data.items.0');
        $this->assertEquals(152, $line['unit_price']);
        $this->assertEquals(160, $line['price']);
        $this->assertSame(5, $line['bulk_applied']['min_qty']);
        $this->assertSame('Buy 5+ for Rs 152 each', $line['bulk_applied']['label']);
        $this->assertEquals(760, $line['line_total']);
        $this->assertEquals(1000, $line['line_mrp_total']);
        $this->assertEquals(240, $line['saving']);
        $this->assertSame(25, $line['coins_to_earn']);

        $s = $res->json('data.summary');
        $this->assertEquals(1000, $s['items_total']);
        $this->assertEquals(200, $s['product_discount']);
        $this->assertEquals(40, $s['bulk_discount']);
        $this->assertEquals(760, $s['subtotal']);
        $this->assertEquals(300, $s['delivery_fee']);
        $this->assertEquals(1060, $s['grand_total']);
        $this->assertSame(25, $s['coins_to_earn']);
        $this->assertEquals(240, $s['total_savings']);
        $this->assertSame('Inclusive of all taxes', $s['tax_note']);
        $this->assertEquals(round(760 * 18 / 118, 2), $s['tax_total']);   // the product's sales tax (18%) is inside the price
        $res->assertJsonPath('data.can_checkout', true);
    }

    public function test_the_highest_bulk_tier_reached_wins_and_the_next_offer_is_hinted(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $one = $this->addToCart($variant, 1)->json('data.items.0');
        $this->assertEquals(160, $one['unit_price']);
        $this->assertNull($one['bulk_applied']);
        $this->assertSame('Buy 5+ for Rs 152 each', $one['next_offer']['hint']);
        $this->assertSame(4, $one['next_offer']['add_qty']);
        $this->assertEquals(152, $one['next_offer']['price']);
        $this->assertEquals(5, $one['next_offer']['extra_discount_percent']);   // (160 - 152) / 160
    }

    public function test_bulk_tiers_follow_the_quantity_up_and_down(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 1)->json('data.token')];

        $at = function (int $qty) use ($variant, $headers) {
            return $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => $qty])->assertOk()->json('data.items.0');
        };

        $this->assertEquals(160, $at(4)['unit_price']);
        $this->assertEquals(152, $at(5)['unit_price']);
        $this->assertEquals(152, $at(9)['unit_price']);
        $ten = $at(10);
        $this->assertEquals(140, $ten['unit_price']);
        $this->assertNull($ten['next_offer']);                    // nothing higher to reach
        $this->assertEquals(1400, $ten['line_total']);
        $this->assertEquals(152, $at(6)['unit_price']);           // back down
    }

    public function test_a_bulk_price_that_is_not_cheaper_than_the_option_price_is_ignored(): void
    {
        // a tier priced above the option price cannot be saved through the admin API, but the database may still hold one
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        DB::table('variant_price_tiers')->where('variant_id', $variant)->where('min_qty', 5)->update(['price' => 170]);

        $this->assertEquals(160, $this->addToCart($variant, 5)->json('data.items.0.unit_price'));
    }

    public function test_delivery_is_free_when_the_subtotal_reaches_the_limit_and_zero_means_never_free(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 5)->json('data.token')];   // subtotal 760

        $this->setSetting('free_delivery_min_cart', 760);
        $s = $this->cart($headers)->json('data.summary');
        $this->assertEquals(0, $s['delivery_fee']);
        $this->assertTrue($s['delivery_free']);
        $this->assertEquals(760, $s['grand_total']);

        $this->setSetting('free_delivery_min_cart', 761);
        $s = $this->cart($headers)->json('data.summary');
        $this->assertEquals(300, $s['delivery_fee']);
        $this->assertEquals(1, $s['amount_for_free_delivery']);

        $this->setSetting('free_delivery_min_cart', 0);   // 0 = no free delivery, ever
        $s = $this->cart($headers)->json('data.summary');
        $this->assertEquals(300, $s['delivery_fee']);
        $this->assertFalse($s['delivery_free']);
        $this->assertNull($s['amount_for_free_delivery']);
    }

    public function test_the_delivery_fee_is_one_admin_setting(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $user = $this->customer();
        $this->actAs($user);
        $this->setSetting('delivery_fee', 120);

        $this->addToCart($variant, 1);

        $this->assertEquals(120, $this->cart()->json('data.summary.delivery_fee'));
    }

    public function test_an_empty_cart_has_no_delivery_fee(): void
    {
        $this->cart()->assertOk()
            ->assertJsonPath('data.summary.grand_total', 0)
            ->assertJsonPath('data.summary.delivery_fee', 0)
            ->assertJsonPath('data.can_checkout', false);
    }

    // ------------------------------------------------------------------ coins

    public function test_coins_are_clamped_to_the_wallet_the_order_amount_and_the_caps(): void
    {
        $user = $this->customer();
        $variant = $this->variantOf($this->shopProduct());
        $this->actAs($user);
        $this->addToCart($variant, 5);   // subtotal 760
        $this->giveCoins($user, 100);

        $coins = fn (int $ask) => $this->putJson(self::STORE.'/cart/coins', ['coins_to_redeem' => $ask])->assertOk()->json('data.summary.coins');

        // wallet: 100 coins, asking for 500
        $c = $coins(500);
        $this->assertSame(100, $c['applied']);
        $this->assertSame(500, $c['requested']);
        $this->assertSame('wallet_balance', $c['limited_by']);
        $this->assertSame(100, $c['wallet_balance']);
        $this->assertEquals(100, $this->cart()->json('data.summary.coins_discount'));
        $this->assertEquals(960, $this->cart()->json('data.summary.grand_total'));   // 760 - 100 + 300

        // exactly what is asked when it is allowed
        $this->assertSame(40, $coins(40)['applied']);
        $this->assertNull($coins(40)['limited_by']);

        // order amount: coins never pay for more than the items (760), even with a rich wallet
        $this->giveCoins($user, 5000);
        $c = $coins(5000);
        $this->assertSame(760, $c['applied']);
        $this->assertSame('order_amount', $c['limited_by']);

        // admin caps
        $this->setSetting('max_redeem_coins_per_order', 300);
        $this->assertSame('max_per_order', $coins(5000)['limited_by']);
        $this->assertSame(300, $coins(5000)['applied']);

        $this->setSetting('max_redeem_coins_per_order', 5000);
        $this->setSetting('redeem_cap_order_percent', 10);       // 10% of 760 = 76
        $c = $coins(5000);
        $this->assertSame(76, $c['applied']);
        $this->assertSame('order_percent_cap', $c['limited_by']);

        $this->setSetting('redeem_cap_order_percent', 100);
        $this->setSetting('redeem_cap_balance_percent', 20);     // 20% of 5100 = 1020, more than the order allows -> order amount still wins
        $this->assertSame(760, $coins(5000)['applied']);
        $this->setSetting('redeem_cap_balance_percent', 5);      // 5% of 5100 = 255
        $c = $coins(5000);
        $this->assertSame(255, $c['applied']);
        $this->assertSame('balance_percent_cap', $c['limited_by']);
    }

    public function test_one_coin_is_one_rupee_by_default_and_the_value_comes_from_the_setting(): void
    {
        $user = $this->customer();
        $variant = $this->variantOf($this->shopProduct());
        $this->actAs($user);
        $this->addToCart($variant, 5);   // subtotal 760
        $this->giveCoins($user, 100);

        $s = $this->putJson(self::STORE.'/cart/coins', ['coins_to_redeem' => 50])->json('data.summary');
        $this->assertEquals(1, $s['coins']['coin_value']);
        $this->assertEquals(50, $s['coins_discount']);

        $this->setSetting('reward_redeem_rate', 2.5);
        $s = $this->cart()->json('data.summary');
        $this->assertEquals(2.5, $s['coins']['coin_value']);
        $this->assertEquals(125, $s['coins_discount']);
        $this->assertEquals(300 + 760 - 125, $s['grand_total']);
    }

    public function test_coins_need_a_login_and_a_guest_cart_shows_no_wallet(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 1)->json('data.token')];

        $this->withHeaders($headers)->putJson(self::STORE.'/cart/coins', ['coins_to_redeem' => 10])->assertStatus(401);
        $this->assertSame(0, $this->cart($headers)->json('data.summary.coins.wallet_balance'));
    }

    // ------------------------------------------------------------------ rules on every change

    public function test_a_line_respects_the_minimum_the_maximum_and_the_stock(): void
    {
        $product = $this->shopProduct(['stock' => 8], ['min_order_qty' => 2, 'max_order_qty' => 6]);
        $variant = $this->variantOf($product);

        $this->addToCart($variant, 1)->assertStatus(422)->assertJsonPath('code', 'below_min_qty')->assertJsonValidationErrors('qty');
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 2)->assertCreated()->json('data.token')];

        $this->addToCart($variant, 5, $headers)->assertStatus(422)->assertJsonPath('code', 'above_max_qty');   // 2 + 5 = 7 > 6
        $this->addToCart($variant, 4, $headers)->assertCreated()->assertJsonPath('data.items.0.qty', 6);       // adds up to the maximum

        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => 1])->assertStatus(422)->assertJsonPath('code', 'below_min_qty');
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => 7])->assertStatus(422)->assertJsonPath('code', 'above_max_qty');

        $this->stockOf($variant)->update(['stock_qty' => 3, 'reserved_qty' => 1]);   // 2 can still be sold
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => 3])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('message', 'Only 2 left in stock for "'.$product->variants()->value('name').'".');
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => 2])->assertOk();
    }

    public function test_an_out_of_stock_inactive_or_hidden_option_cannot_be_added(): void
    {
        $soldOut = $this->variantOf($this->shopProduct(['stock' => 0]));
        $this->addToCart($soldOut, 1)->assertStatus(422)->assertJsonPath('code', 'out_of_stock');

        $draft = $this->shopProduct([], ['status' => 'draft']);
        $this->addToCart($this->variantOf($draft), 1)->assertStatus(422)->assertJsonPath('code', 'unavailable');

        $off = $this->shopProduct();
        $off->variants()->update(['is_active' => false]);
        $this->addToCart($this->variantOf($off), 1)->assertStatus(422)->assertJsonPath('code', 'unavailable');

        $this->addToCart(999999, 1)->assertStatus(422)->assertJsonValidationErrors('variant_id');
        $this->addToCart($soldOut, 0)->assertStatus(422)->assertJsonValidationErrors('qty');
    }

    public function test_adding_the_same_option_twice_keeps_one_line(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 2)->json('data.token')];

        $res = $this->addToCart($variant, 3, $headers)->assertCreated();

        $this->assertSame(1, $res->json('data.lines_count'));
        $this->assertSame(5, $res->json('data.items.0.qty'));
        $this->assertSame(1, DB::table('cart_items')->count());
    }

    public function test_quantity_can_be_changed_and_lines_removed_and_the_cart_cleared(): void
    {
        $a = $this->variantOf($this->shopProduct());
        $b = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($a, 2)->json('data.token')];
        $this->addToCart($b, 1, $headers);

        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$a", ['qty' => 4])->assertOk()->assertJsonPath('data.items.0.qty', 4);
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$a", ['qty' => 1])->assertOk()->assertJsonPath('data.items.0.qty', 1);
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$a", ['qty' => 0])->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->withHeaders($headers)->putJson(self::STORE.'/cart/items/'.($b + 100), ['qty' => 1])->assertStatus(404)->assertJsonPath('code', 'not_in_cart');

        $this->withHeaders($headers)->deleteJson(self::STORE."/cart/items/$a")->assertOk()->assertJsonPath('data.lines_count', 1)->assertJsonPath('data.items.0.variant_id', $b);
        $this->withHeaders($headers)->deleteJson(self::STORE."/cart/items/$a")->assertOk();   // removing twice is fine
        $this->withHeaders($headers)->deleteJson(self::STORE.'/cart')->assertOk()->assertJsonPath('data.lines_count', 0)->assertJsonPath('data.summary.grand_total', 0);
    }

    // ------------------------------------------------------------------ channels

    // ------------------------------------------------------------------ warnings

    public function test_a_line_whose_price_changed_shows_a_warning_and_stays(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 1)->json('data.token')];

        $product->variants()->update(['selling_price' => 170, 'discount_percent' => 15]);

        $line = $this->cart($headers)->json('data.items.0');
        $this->assertEquals(170, $line['unit_price']);               // never the old price
        $this->assertEquals(['from' => 160, 'to' => 170], $line['price_changed']);
        $this->assertSame('price_changed', $line['warnings'][0]['code']);
        $this->assertFalse($line['warnings'][0]['blocking']);
        $this->assertTrue($line['can_buy']);
        $this->assertEquals(170 + 300, $this->cart($headers)->json('data.summary.grand_total'));

        // touching the line accepts the new price
        $this->withHeaders($headers)->putJson(self::STORE."/cart/items/$variant", ['qty' => 2])->assertOk();
        $this->assertNull($this->cart($headers)->json('data.items.0.price_changed'));
    }

    public function test_stock_dropping_after_adding_warns_without_dropping_the_line(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        $other = $this->variantOf($this->shopProduct());
        $headers = ['X-Cart-Token' => $this->addToCart($variant, 6)->json('data.token')];
        $this->addToCart($other, 1, $headers);

        $this->stockOf($variant)->update(['stock_qty' => 4]);
        $res = $this->cart($headers);
        $line = $res->json('data.items.0');
        $this->assertSame(6, $line['qty']);                           // not touched
        $this->assertSame('insufficient_stock', $line['warnings'][0]['code']);
        $this->assertSame(4, $line['available_qty']);
        $this->assertFalse($line['can_buy']);
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame('insufficient_stock', $res->json('data.issues.0.code'));

        $this->stockOf($variant)->update(['stock_qty' => 0]);
        $res = $this->cart($headers);
        $line = $res->json('data.items.0');
        $this->assertSame('out_of_stock', $line['warnings'][0]['code']);
        $this->assertFalse($line['in_totals']);
        $this->assertEquals(160 + 300, $res->json('data.summary.grand_total'));   // only the other line counts
        $this->assertSame(2, $res->json('data.lines_count'));

        $product->update(['status' => ProductStatus::Inactive]);
        $this->assertSame('unavailable', $this->cart($headers)->json('data.items.0.warnings.0.code'));
    }

    public function test_a_product_deleted_after_it_was_added_is_reported_not_a_server_error(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);
        $user = $this->customer();
        $address = $this->address($user)->id;
        $this->actAs($user);
        $this->addToCart($variant, 2)->assertCreated();

        $product->delete();                                   // soft delete
        $product->brand()->first()->delete();                 // and its brand

        $res = $this->cart()->assertOk();
        $this->assertSame('unavailable', $res->json('data.items.0.warnings.0.code'));
        $this->assertSame($product->name, $res->json('data.items.0.name'));
        $this->assertFalse($res->json('data.can_checkout'));
        $this->placeOrder(['address_id' => $address])->assertStatus(422)->assertJsonPath('code', 'cart_invalid');
        $this->deleteJson(self::STORE."/cart/items/$variant")->assertOk()->assertJsonPath('data.lines_count', 0);   // the customer can still clean up
    }

    public function test_a_low_stock_line_says_how_many_are_left(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 3]));

        $line = $this->addToCart($variant, 1)->json('data.items.0');

        $this->assertSame('low_stock', $line['warnings'][0]['code']);
        $this->assertSame('Only 3 left.', $line['warnings'][0]['message']);
        $this->assertTrue($line['can_buy']);
    }

    // ------------------------------------------------------------------ guest -> customer

    public function test_logging_in_merges_the_guest_cart_into_the_customer_cart(): void
    {
        $user = $this->customer('merge@example.test');
        $a = $this->variantOf($this->shopProduct());
        $b = $this->variantOf($this->shopProduct());

        // the customer already has 2 of A in the cart
        $this->actAs($user);
        $this->addToCart($a, 2);

        // as a guest: 3 of A and 1 of B
        $this->actAs(null);
        $token = $this->addToCart($a, 3)->json('data.token');
        $this->addToCart($b, 1, ['X-Cart-Token' => $token]);

        $this->actAs(null);
        $res = $this->postJson(self::STORE.'/auth/login', ['login' => 'merge@example.test', 'password' => 'secret-pass', 'cart_token' => $token])->assertOk();
        $this->assertSame(2, $res->json('cart_lines_merged'));

        $this->actAs($user);
        $lines = collect($this->cart()->json('data.items'))->keyBy('variant_id');
        $this->assertSame(5, $lines[$a]['qty']);   // same option -> quantities added
        $this->assertSame(1, $lines[$b]['qty']);
        $this->assertSame(0, DB::table('carts')->whereNotNull('guest_token')->count());   // the guest cart is gone: a token works once
    }

    public function test_the_merge_is_capped_by_max_order_qty_and_stock_and_skips_what_is_no_longer_sold(): void
    {
        $user = $this->customer('cap@example.test');
        $capped = $this->variantOf($this->shopProduct(['stock' => 7], ['max_order_qty' => 10]));
        $gone = $this->shopProduct();
        $goneVariant = $this->variantOf($gone);

        $this->actAs($user);
        $this->addToCart($capped, 4);

        $this->actAs(null);
        $token = $this->addToCart($capped, 5)->json('data.token');
        $this->addToCart($goneVariant, 1, ['X-Cart-Token' => $token]);
        $gone->update(['status' => ProductStatus::Inactive]);

        $this->actAs(null);
        $res = $this->postJson(self::STORE.'/auth/login', ['login' => 'cap@example.test', 'password' => 'secret-pass'], ['X-Cart-Token' => $token])->assertOk();
        $this->assertSame(1, $res->json('cart_lines_merged'));

        $this->actAs($user);
        $lines = $this->cart()->json('data.items');
        $this->assertCount(1, $lines);
        $this->assertSame(7, $lines[0]['qty']);   // 4 + 5 = 9 but only 7 in stock
    }

    public function test_registering_merges_the_guest_cart_too(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->addToCart($variant, 3)->json('data.token');

        $res = $this->postJson(self::STORE.'/auth/register', [
            'full_name' => 'New Buyer', 'email' => 'new@example.test', 'mobile' => '0300 1112223', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
            'cart_token' => $token,
        ])->assertCreated();

        $this->assertSame(1, $res->json('cart_lines_merged'));
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'mobile' => '03001112223']);

        $this->actAs(\App\Domain\Auth\Models\User::where('email', 'new@example.test')->first());
        $this->assertSame(3, $this->cart()->json('data.items.0.qty'));

        $this->postJson(self::STORE.'/auth/register', ['full_name' => 'X', 'email' => 'new@example.test', 'mobile' => '0300 1112223', 'password' => 'secret-pass', 'password_confirmation' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'mobile', 'password']);
    }

    // ------------------------------------------------------------------ privacy, shape, cost

    public function test_a_customer_only_ever_sees_their_own_cart(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $alice = $this->customer('alice@example.test');
        $bob = $this->customer('bob@example.test');

        $this->actAs($alice);
        $this->addToCart($variant, 3);

        $this->actAs($bob);
        $this->cart()->assertOk()->assertJsonPath('data.items_count', 0);
        $this->addToCart($variant, 1);

        $this->actAs($alice);
        $this->assertSame(3, $this->cart()->json('data.items.0.qty'));
        $this->deleteJson(self::STORE.'/cart')->assertOk();

        $this->actAs($bob);
        $this->assertSame(1, $this->cart()->json('data.items.0.qty'));   // Alice emptying her cart did not touch Bob's
    }

    public function test_a_customer_cart_cannot_be_reached_with_a_guest_token_and_an_admin_token_is_just_a_guest(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $user = $this->customer();
        $this->actAs($user);
        $this->addToCart($variant, 2);
        $this->assertNull(Cart::where('user_id', $user->id)->value('guest_token'));

        $this->actingAsAdmin();
        $this->cart()->assertOk()->assertJsonPath('data.items_count', 0);
    }

    public function test_the_line_has_a_stable_shape_with_absolute_image_urls_or_null(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);

        $line = $this->addToCart($variant, 1)->json('data.items.0');

        $this->assertSame(['variant_id', 'product_id', 'slug', 'name', 'option_name', 'sku', 'image_url', 'thumb_url', 'brand', 'qty', 'min_order_qty', 'max_order_qty', 'available_qty',
            'mrp', 'price', 'unit_price', 'bulk_applied', 'next_offer', 'line_mrp_total', 'line_total', 'product_saving', 'bulk_saving', 'saving',
            'tax_rate', 'tax', 'in_totals', 'can_buy', 'price_changed', 'warnings', 'reward_points', 'coins_to_earn'], array_keys($line));
        $this->assertSame(['name', 'slug'], array_keys($line['brand']));
        $this->assertNull($line['image_url']);   // the test product has no picture: null, never a relative path
        $this->assertSame($product->variants()->value('name'), $line['option_name']);
        $this->assertSame($product->name, $line['name']);

        $this->assertSame(['token', 'currency', 'currency_symbol', 'items_count', 'lines_count', 'items', 'summary', 'can_checkout', 'issues'], array_keys($this->cart()->json('data')));
    }

    public function test_the_cart_costs_a_fixed_number_of_queries_however_many_lines_it_has(): void
    {
        $user = $this->customer();
        $this->actAs($user);
        $this->addToCart($this->variantOf($this->shopProduct()), 1);

        DB::enableQueryLog();
        $this->cart()->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(1, 5) as $_) {
            $this->addToCart($this->variantOf($this->shopProduct()), 1);
        }
        DB::flushQueryLog();
        $res = $this->cart()->assertOk();
        $six = count(DB::getQueryLog());

        $this->assertSame(6, $res->json('data.lines_count'));
        $this->assertSame($one, $six, 'the cart must not run a query per line');
    }

    public function test_cart_routes_are_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->cart()->assertOk();
        }
        $this->cart()->assertStatus(429);
    }

    public function test_the_prune_command_removes_expired_guest_carts_and_old_empty_customer_carts(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $token = $this->addToCart($variant, 1)->json('data.token');
        $user = $this->customer();
        $this->actAs($user);
        $this->addToCart($variant, 1);

        $this->artisan('carts:prune')->assertSuccessful();
        $this->assertSame(2, Cart::count());   // nothing is old yet

        Cart::where('guest_token', $token)->update(['expires_at' => now()->subDay()]);
        $old = Cart::create(['user_id' => $this->customer('old@example.test')->id, 'status' => 'active']);
        DB::table('carts')->where('id', $old->id)->update(['updated_at' => now()->subDays(120)]);

        $this->artisan('carts:prune')->assertSuccessful();

        $this->assertDatabaseMissing('carts', ['guest_token' => $token]);
        $this->assertDatabaseMissing('carts', ['id' => $old->id]);
        $this->assertSame(1, Cart::count());   // the customer's cart with items stays
    }
}

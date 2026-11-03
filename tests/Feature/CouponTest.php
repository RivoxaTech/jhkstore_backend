<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Coupons: the rules, best-one-wins against the automatic discounts, the usage counters (race safe) and giving a use back. */
class CouponTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
        $this->setSetting('free_delivery_min_cart', 0);
    }

    /** Quote of one 160 item (price 160, MRP 200) with a coupon code. */
    private function quoteWith(int $variant, ?string $code, array $extra = [], int $qty = 1)
    {
        return $this->quote([$variant => $qty], [], ($code === null ? [] : ['coupon_code' => $code]) + $extra)->assertOk();
    }

    private function orderWith(int $variant, ?string $code, array $overrides = [], int $qty = 1)
    {
        return $this->placeOrder($this->orderBody([$variant => $qty], ($code === null ? [] : ['coupon_code' => $code]) + $overrides));
    }

    public function test_a_percent_coupon_takes_a_share_off_and_the_code_is_case_insensitive(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'SAVE10']);

        $res = $this->quoteWith($variant, ' save10 ');

        $s = $res->json('data.summary');
        $this->assertSame('SAVE10', $s['coupon_code']);
        $this->assertEquals(16, $s['coupon_discount']);
        $this->assertEquals(0, $s['discount_total']);
        $this->assertNull($s['coupon_error']);
        $this->assertEquals(144, $s['subtotal']);
        $line = $res->json('data.items.0');
        $this->assertSame('coupon', $line['discount_source']);
        $this->assertSame($coupon->id, $line['coupon_id']);
        $this->assertNull($line['discount_id']);
    }

    public function test_a_coupon_that_does_not_work_is_explained_in_the_quote_and_does_not_block_it(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $other = $this->shopProduct();
        $this->makeCoupon(['code' => 'OFF', 'is_active' => false]);
        $this->makeCoupon(['code' => 'OLD', 'ends_at' => now()->subMinute()]);
        $this->makeCoupon(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $this->makeCoupon(['code' => 'BIG', 'min_order' => 500]);
        $this->makeCoupon(['code' => 'ELSEWHERE', 'scope' => 'products', 'targets' => [$other->id]]);
        $this->makeCoupon(['code' => 'GONE', 'usage_limit' => 1])->forceFill(['used_count' => 1])->save();

        foreach (['NOPE' => 'not_found', 'OFF' => 'inactive', 'OLD' => 'expired', 'SOON' => 'not_started', 'BIG' => 'min_order', 'ELSEWHERE' => 'no_eligible_items', 'GONE' => 'usage_limit'] as $code => $reason) {
            $res = $this->quoteWith($variant, $code);
            $this->assertSame($reason, $res->json('data.summary.coupon_error.code'), $code);
            $this->assertNotSame('', $res->json('data.summary.coupon_error.message'));
            $this->assertEquals(0, $res->json('data.summary.coupon_discount'));
            $this->assertNull($res->json('data.summary.coupon_code'));
            $this->assertTrue($res->json('data.can_checkout'), "$code must not block the quote");
            $this->assertEquals(160, $res->json('data.summary.grand_total'));
        }
    }

    public function test_the_per_customer_rule_is_only_checked_in_a_quote_that_knows_the_phone(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'ONCE', 'per_customer_limit' => 1]);
        $this->orderWith($variant, 'ONCE', ['customer' => $this->contact(['phone' => '+1 555 000 1111'])])->assertCreated();

        $this->assertNull($this->quoteWith($variant, 'ONCE')->json('data.summary.coupon_error'));                       // no phone: cannot know
        $this->assertSame('per_customer_limit', $this->quoteWith($variant, 'ONCE', ['phone' => '+1 (555) 000-1111'])->json('data.summary.coupon_error.code'));
        $this->assertNull($this->quoteWith($variant, 'ONCE', ['phone' => '+1 555 000 9999'])->json('data.summary.coupon_error'));
    }

    public function test_the_order_is_refused_with_coupon_invalid_and_leaves_nothing_behind(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'OFF', 'is_active' => false]);

        $res = $this->orderWith($variant, 'OFF')->assertStatus(422);

        $res->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'inactive')->assertJsonPath('coupon_error.code', 'inactive');
        $this->orderWith($variant, 'NOPE')->assertStatus(422)->assertJsonPath('reason', 'not_found');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
    }

    public function test_the_minimum_order_of_a_coupon_is_checked_after_bulk_prices_and_automatic_discounts(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'MIN150', 'value' => 5, 'min_order' => 150]);

        $this->assertNull($this->quoteWith($variant, 'MIN150')->json('data.summary.coupon_error'));   // 160 >= 150

        $this->makeDiscount(['value' => 10]);                                                          // 160 -> 144 < 150
        $this->assertSame('min_order', $this->quoteWith($variant, 'MIN150')->json('data.summary.coupon_error.code'));

        $this->setSetting('free_delivery_min_cart', 0);
        $this->assertSame('min_order', $this->quoteWith($variant, 'MIN150')->json('data.summary.coupon_error.code'));
    }

    public function test_per_line_the_better_of_the_automatic_discount_and_the_coupon_wins_never_both(): void
    {
        $a = $this->shopProduct();
        $b = $this->shopProduct();
        [$va, $vb] = [$this->variantOf($a), $this->variantOf($b)];
        $this->makeDiscount(['value' => 50, 'scope' => 'products', 'targets' => [$a->id]]);
        $this->makeCoupon(['code' => 'TWENTY', 'value' => 20]);

        $res = $this->quote([$va => 1, $vb => 1], [], ['coupon_code' => 'TWENTY'])->assertOk();

        $this->assertSame('discount', $res->json('data.items.0.discount_source'));   // 50 % beats 20 %
        $this->assertEquals(80, $res->json('data.items.0.discount_amount'));
        $this->assertSame('coupon', $res->json('data.items.1.discount_source'));     // the other line only has the coupon
        $this->assertEquals(32, $res->json('data.items.1.discount_amount'));
        $this->assertEquals(80, $res->json('data.summary.discount_total'));
        $this->assertEquals(32, $res->json('data.summary.coupon_discount'));
        $this->assertEquals(320 - 112, $res->json('data.summary.grand_total'));
    }

    public function test_a_coupon_that_is_worse_than_the_discount_everywhere_is_reported_and_not_counted(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeDiscount(['value' => 20]);
        $this->makeCoupon(['code' => 'TEN', 'value' => 10]);

        $res = $this->quoteWith($variant, 'TEN');

        $this->assertSame('no_eligible_items', $res->json('data.summary.coupon_error.code'));
        $this->assertEquals(32, $res->json('data.summary.discount_total'));
        $this->assertEquals(0, $res->json('data.summary.coupon_discount'));
        $this->orderWith($variant, 'TEN')->assertStatus(422)->assertJsonPath('code', 'coupon_invalid');
    }

    public function test_a_better_coupon_replaces_the_automatic_discount_on_the_line(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeDiscount(['value' => 10]);
        $this->makeCoupon(['code' => 'THIRTY', 'value' => 30]);

        $s = $this->quoteWith($variant, 'THIRTY')->json('data.summary');

        $this->assertEquals(0, $s['discount_total']);
        $this->assertEquals(48, $s['coupon_discount']);
        $this->assertEquals(112, $s['subtotal']);
    }

    public function test_the_maximum_discount_caps_the_coupon_in_total(): void
    {
        $a = $this->variantOf($this->shopProduct());
        $b = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'HALF', 'value' => 50, 'max_discount' => 30]);

        $res = $this->quote([$a => 1, $b => 1], [], ['coupon_code' => 'HALF'])->assertOk();

        $this->assertEquals(30, $res->json('data.summary.coupon_discount'));     // 80 + 80 would be 160
        $this->assertEquals(15, $res->json('data.items.0.discount_amount'));
        $this->assertEquals(15, $res->json('data.items.1.discount_amount'));
        $this->assertEquals(290, $res->json('data.summary.grand_total'));
    }

    public function test_a_fixed_coupon_is_split_by_line_total_and_stays_inside_the_cart(): void
    {
        $a = $this->variantOf($this->shopProduct(['mrp' => 100, 'selling_price' => 100, 'price_tiers' => []]));
        $b = $this->variantOf($this->shopProduct(['mrp' => 200, 'selling_price' => 200, 'price_tiers' => []]));
        $this->makeCoupon(['code' => 'TEN', 'type' => 'fixed', 'value' => 10]);
        $this->makeCoupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 9999]);

        $res = $this->quote([$a => 1, $b => 1], [], ['coupon_code' => 'TEN'])->assertOk();
        $this->assertEquals(3.33, $res->json('data.items.0.discount_amount'));
        $this->assertEquals(6.67, $res->json('data.items.1.discount_amount'));

        $res = $this->quote([$a => 1, $b => 1], [], ['coupon_code' => 'HUGE'])->assertOk();
        $this->assertEquals(300, $res->json('data.summary.coupon_discount'));    // never more than the items cost
        $this->assertEquals(0, $res->json('data.summary.grand_total'));
    }

    public function test_a_free_delivery_coupon_takes_nothing_off_the_items_and_makes_delivery_free(): void
    {
        $this->setSetting('delivery_fee', 50);
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'FREESHIP', 'type' => 'free_delivery', 'value' => null, 'free_delivery' => true]);

        $s = $this->quoteWith($variant, 'FREESHIP')->json('data.summary');
        $this->assertEquals(0, $s['delivery_fee']);
        $this->assertTrue($s['delivery_free']);
        $this->assertTrue($s['coupon_free_delivery']);
        $this->assertEquals(0, $s['coupon_discount']);
        $this->assertEquals(160, $s['grand_total']);
        $this->assertEquals(210, $this->quoteWith($variant, null)->json('data.summary.grand_total'));

        $order = Order::where('order_number', $this->orderWith($variant, 'FREESHIP')->assertCreated()->json('data.order_number'))->first();
        $this->assertEquals(0, $order->shipping_fee);
        $this->assertSame($coupon->id, (int) $order->coupon_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_an_order_with_a_coupon_records_the_use(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'SAVE10']);

        $number = $this->orderWith($variant, 'save10', ['customer' => $this->contact(['phone' => '0300 1234567'])])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->first();
        $this->assertSame($coupon->id, (int) $order->coupon_id);
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertEquals(16, $order->coupon_discount);
        $this->assertEquals(0, $order->discount_total);
        $this->assertEquals(144, $order->grand_total);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $usage = CouponUsage::where('coupon_id', $coupon->id)->first();
        $this->assertSame($order->id, (int) $usage->order_id);
        $this->assertSame($order->customer_id, (int) $usage->customer_id);
        $this->assertSame($order->customer_phone, $usage->phone);   // the normalised phone
        $this->assertEquals(16, $usage->amount);
        $this->assertNull($usage->released_at);
    }

    public function test_a_coupon_that_took_nothing_off_is_not_written_down_as_used(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'SAVE10']);

        $this->orderWith($variant, null)->assertCreated();

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_the_last_use_of_a_coupon_goes_to_one_order_only(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'LAST', 'usage_limit' => 1]);
        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = strtolower($q->sql);
        });

        $this->orderWith($variant, 'LAST')->assertCreated();
        $second = $this->orderWith($variant, 'LAST')->assertStatus(422);

        $second->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'usage_limit');
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, CouponUsage::count());
        $this->assertSame(49, $this->stockOf($variant)->stock_qty);   // the refused order took no stock

        // what makes the two orders race safe when they really are simultaneous: the stock rows and then the coupon row are locked
        // FOR UPDATE inside the order transaction, always in that order, before the usage counter is read
        $stockLock = collect($log)->search(fn ($sql) => str_contains($sql, 'from `product_variants`') && str_contains($sql, 'for update'));
        $couponLock = collect($log)->search(fn ($sql) => str_contains($sql, 'from `coupons`') && str_contains($sql, 'for update'));
        $this->assertNotFalse($stockLock);
        $this->assertNotFalse($couponLock);
        $this->assertLessThan($couponLock, $stockLock);
    }

    public function test_the_limit_per_customer_counts_the_normalised_phone(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'ONCE', 'per_customer_limit' => 1]);

        $this->orderWith($variant, 'ONCE', ['customer' => $this->contact(['phone' => '+1 555 000 2222'])])->assertCreated();
        $this->orderWith($variant, 'ONCE', ['customer' => $this->contact(['phone' => '+1-555-000-2222'])])
            ->assertStatus(422)->assertJsonPath('reason', 'per_customer_limit');
        $this->orderWith($variant, 'ONCE', ['customer' => $this->contact(['phone' => '+1 555 000 3333'])])->assertCreated();
    }

    public function test_a_first_order_coupon_is_refused_to_a_phone_with_an_earlier_order(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'WELCOME', 'first_order_only' => true]);
        $phone = '+1 555 000 4444';

        $this->orderWith($variant, null, ['customer' => $this->contact(['phone' => $phone])])->assertCreated();
        $this->orderWith($variant, 'WELCOME', ['customer' => $this->contact(['phone' => $phone])])->assertStatus(422)->assertJsonPath('reason', 'first_order_only');
        $this->assertSame('first_order_only', $this->quoteWith($variant, 'WELCOME', ['phone' => $phone])->json('data.summary.coupon_error.code'));

        $this->orderWith($variant, 'WELCOME', ['customer' => $this->contact(['phone' => '+1 555 000 5555'])])->assertCreated();   // a new customer
    }

    public function test_a_cancelled_or_rejected_order_gives_the_coupon_use_back(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'LAST', 'usage_limit' => 1, 'per_customer_limit' => 1, 'first_order_only' => true]);
        $phone = '+1 555 000 6666';

        $first = Order::where('order_number', $this->orderWith($variant, 'LAST', ['customer' => $this->contact(['phone' => $phone])])->assertCreated()->json('data.order_number'))->first();
        $this->assertSame(1, $coupon->fresh()->used_count);

        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$first->id}/status", ['status' => 'cancelled', 'reason' => 'Changed my mind'])->assertOk();

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertNotNull(CouponUsage::first()->released_at);

        // the same phone can use it again (it counts neither as a use nor as an earlier order), and a rejection gives it back as well
        $again = Order::where('order_number', $this->orderWith($variant, 'LAST', ['customer' => $this->contact(['phone' => $phone])])->assertCreated()->json('data.order_number'))->first();
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$again->id}/status", ['status' => 'rejected', 'reason' => 'Out of the area'])->assertOk();
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(2, CouponUsage::whereNotNull('released_at')->count());
    }

    public function test_the_validate_endpoint_returns_the_quote_with_the_coupon_applied(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeCoupon(['code' => 'SAVE10']);

        $ok = $this->flushHeaders()->postJson(self::STORE.'/coupons/validate', ['code' => 'save10', 'items' => [['variant_id' => $variant, 'qty' => 1]]])->assertOk();
        $this->assertEquals(16, $ok->json('data.summary.coupon_discount'));
        $this->assertEquals(144, $ok->json('data.summary.grand_total'));

        $this->postJson(self::STORE.'/coupons/validate', ['code' => 'nope', 'items' => [['variant_id' => $variant, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('coupon_error.code', 'not_found');
        $this->postJson(self::STORE.'/coupons/validate', ['items' => [['variant_id' => $variant, 'qty' => 1]]])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson(self::STORE.'/coupons/validate', ['code' => 'SAVE10', 'website' => 'bot', 'items' => [['variant_id' => $variant, 'qty' => 1]]])->assertStatus(422)->assertJsonPath('code', 'rejected');
    }

    public function test_the_coupon_code_check_is_rate_limited(): void
    {
        config(['shop.rate.coupon_per_minute' => 3]);
        $variant = $this->variantOf($this->shopProduct());
        $body = ['code' => 'GUESS', 'items' => [['variant_id' => $variant, 'qty' => 1]]];

        foreach (range(1, 3) as $i) {
            $this->flushHeaders()->postJson(self::STORE.'/coupons/validate', $body)->assertStatus(422);
        }
        $this->postJson(self::STORE.'/coupons/validate', $body)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_coupon_codes_are_stored_in_capitals(): void
    {
        $this->assertSame('MIXED-1', Coupon::normaliseCode(' mixed-1 '));
        $this->assertMatchesRegularExpression(Coupon::CODE_PATTERN, 'A_B-9');
        $this->assertDoesNotMatchRegularExpression(Coupon::CODE_PATTERN, 'ab');
    }
}

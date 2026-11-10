<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Promotion\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Automatic discounts: scopes, percent and fixed values, best-one-wins, never below zero, the order snapshot and the prices of the product lists. */
class DiscountTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);          // keeps the totals easy to read
        $this->setSetting('free_delivery_min_cart', 0);
    }

    private function line(int $variantId, int $qty = 1): array
    {
        return $this->quote([$variantId => $qty])->assertOk()->json('data.items.0');
    }

    public function test_a_percent_discount_takes_a_share_off_the_line_after_the_bulk_price_and_tax_follows(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // MRP 200, price 160, bulk 5+ = 152
        $discount = $this->makeDiscount(['value' => 10]);

        $res = $this->quote([$variant => 5])->assertOk();
        $line = $res->json('data.items.0');
        $this->assertEquals(76, $line['discount_amount']);           // 10 % of 5 x 152
        $this->assertSame('discount', $line['discount_source']);
        $this->assertSame($discount->id, $line['discount_id']);
        $this->assertNull($line['coupon_id']);
        $this->assertEquals(684, $line['line_total']);
        $this->assertEquals(round(684 * 18 / 118, 2), $line['tax']);   // tax is worked out on what is paid

        $s = $res->json('data.summary');
        $this->assertEquals(76, $s['discount_total']);
        $this->assertEquals(684, $s['subtotal']);
        $this->assertEquals(684, $s['grand_total']);
        $this->assertEquals(40 + 200 + 76, $s['total_savings']);     // bulk + product + discount
    }

    public function test_the_order_keeps_the_discount_on_the_line_and_in_the_totals(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $discount = $this->makeDiscount(['value' => 25]);

        $number = $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->with('items')->first();
        $this->assertEquals(40, $order->discount_total);
        $this->assertEquals(0, $order->coupon_discount);
        $this->assertEquals(120, $order->items_subtotal);
        $this->assertEquals(120, $order->grand_total);
        $item = $order->items->first();
        $this->assertEquals(40, $item->discount_amount);
        $this->assertSame('discount', $item->discount_source);
        $this->assertSame($discount->id, (int) $item->discount_source_id);
        $this->assertEquals(120, $item->line_total);
        $this->assertEquals(40, $item->discount);   // the MRP -> price part (200 - 160) is unchanged
    }

    public function test_a_fixed_discount_is_split_by_line_total_and_the_remainder_goes_to_the_last_line(): void
    {
        $a = $this->variantOf($this->shopProduct(['mrp' => 100, 'selling_price' => 100, 'price_tiers' => []]));
        $b = $this->variantOf($this->shopProduct(['mrp' => 200, 'selling_price' => 200, 'price_tiers' => []]));
        $this->makeDiscount(['type' => 'fixed', 'value' => 10]);

        $res = $this->quote([$a => 1, $b => 1])->assertOk();

        $this->assertEquals(3.33, $res->json('data.items.0.discount_amount'));
        $this->assertEquals(6.67, $res->json('data.items.1.discount_amount'));   // 3.33 + 6.67 = exactly 10.00
        $this->assertEquals(10, $res->json('data.summary.discount_total'));
        $this->assertEquals(290, $res->json('data.summary.grand_total'));
    }

    public function test_a_fixed_discount_never_takes_a_line_below_zero(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeDiscount(['type' => 'fixed', 'value' => 500]);

        $line = $this->line($variant);

        $this->assertEquals(160, $line['discount_amount']);
        $this->assertEquals(0, $line['line_total']);
    }

    public function test_the_scope_decides_which_products_a_discount_covers(): void
    {
        $category = $this->makeCategory('Scope Cat');
        $sub = $this->makeSub($category, 'Scope Sub');
        $otherSub = $this->makeSub($category, 'Other Sub');
        $outside = $this->shopProduct();
        $inCategory = $this->makeProduct($category, ['variants' => [['name' => 'Plain', 'sku' => 'PLAIN-1', 'is_default' => true, 'mrp' => 100, 'selling_price' => 100, 'stock' => 10]]]);
        $inSub = $this->makeProduct($category, ['sub_category_id' => $sub->id, 'variants' => [['name' => 'In sub', 'sku' => 'INSUB-1', 'is_default' => true, 'mrp' => 100, 'selling_price' => 100, 'stock' => 10]]]);
        $inOtherSub = $this->makeProduct($category, ['sub_category_id' => $otherSub->id, 'variants' => [['name' => 'Other sub', 'sku' => 'OTHER-1', 'is_default' => true, 'mrp' => 100, 'selling_price' => 100, 'stock' => 10]]]);
        $ids = array_map(fn ($p) => $this->variantOf($p), [$outside, $inCategory, $inSub, $inOtherSub]);
        $amounts = fn () => collect($this->quote(array_fill_keys($ids, 1))->assertOk()->json('data.items'))->pluck('discount_amount')->map(fn ($v) => (float) $v)->all();

        $products = $this->makeDiscount(['scope' => 'products', 'targets' => [$inSub->id], 'value' => 10]);
        $this->assertSame([0.0, 0.0, 10.0, 0.0], $amounts());

        $products->update(['scope' => 'categories']);
        $products->targets()->delete();
        \App\Domain\Promotion\Models\DiscountTarget::create(['discount_id' => $products->id, 'type' => 'category', 'target_id' => $category->id]);
        $this->assertSame([0.0, 10.0, 10.0, 10.0], $amounts());     // every product of the category, sub-categories included

        $products->update(['scope' => 'sub_categories']);
        $products->targets()->delete();
        \App\Domain\Promotion\Models\DiscountTarget::create(['discount_id' => $products->id, 'type' => 'sub_category', 'target_id' => $sub->id]);
        $this->assertSame([0.0, 0.0, 10.0, 0.0], $amounts());       // only that sub-category

        $products->update(['scope' => 'store']);
        $this->assertSame([16.0, 10.0, 10.0, 10.0], $amounts());
    }

    public function test_the_best_discount_wins_per_line_and_discounts_are_never_stacked(): void
    {
        $a = $this->shopProduct();
        $b = $this->shopProduct();
        [$va, $vb] = [$this->variantOf($a), $this->variantOf($b)];
        $this->makeDiscount(['name' => 'Everything', 'value' => 10]);
        $big = $this->makeDiscount(['name' => 'Product A', 'value' => 30, 'scope' => 'products', 'targets' => [$a->id]]);

        $res = $this->quote([$va => 1, $vb => 1])->assertOk();

        $this->assertEquals(48, $res->json('data.items.0.discount_amount'));        // 30 % of 160, not 30 % + 10 %
        $this->assertSame($big->id, $res->json('data.items.0.discount_id'));
        $this->assertEquals(16, $res->json('data.items.1.discount_amount'));        // the other line keeps the store discount
        $this->assertEquals(64, $res->json('data.summary.discount_total'));
    }

    public function test_on_a_tie_the_higher_priority_wins(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $low = $this->makeDiscount(['name' => 'Ten percent', 'value' => 10, 'priority' => 1]);
        $high = $this->makeDiscount(['name' => 'Sixteen off', 'type' => 'fixed', 'value' => 16, 'priority' => 5]);   // 16 = 10 % of 160

        $this->assertSame($high->id, $this->line($variant)['discount_id']);

        $high->update(['priority' => 0]);
        $this->assertSame($low->id, $this->line($variant)['discount_id']);
    }

    public function test_inactive_scheduled_and_expired_discounts_do_not_apply(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->makeDiscount(['name' => 'Off', 'is_active' => false]);
        $this->makeDiscount(['name' => 'Later', 'starts_at' => now()->addDay()]);
        $this->makeDiscount(['name' => 'Over', 'ends_at' => now()->subMinute()]);
        $this->assertEquals(0, $this->line($variant)['discount_amount']);

        $this->makeDiscount(['name' => 'Now', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->assertEquals(16, $this->line($variant)['discount_amount']);
    }

    public function test_a_discount_that_is_saved_applies_at_once_and_a_deleted_one_stops(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->assertEquals(0, $this->line($variant)['discount_amount']);

        $discount = $this->makeDiscount();
        $this->assertEquals(16, $this->line($variant)['discount_amount']);

        $discount->delete();
        $this->assertEquals(0, $this->line($variant)['discount_amount']);
    }

    // ------------------------------------------------------------------ the product lists

    public function test_the_product_cards_and_the_detail_show_the_discounted_price(): void
    {
        $product = $this->shopProduct();
        $this->makeDiscount(['value' => 25]);

        $card = collect($this->getJson(self::STORE.'/products')->assertOk()->json('data'))->firstWhere('id', $product->id);
        $this->assertEquals(120, $card['price']);
        $this->assertEquals(200, $card['compare_price']);
        $this->assertSame('25% off', $card['discount_label']);
        $this->assertEquals(200, $card['mrp']);

        $variant = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->json('data.variants.0');
        $this->assertEquals(120, $variant['price']);
        $this->assertEquals(160, $variant['option_price']);
        $this->assertEquals(200, $variant['compare_price']);
        $this->assertSame('25% off', $variant['discount_label']);
    }

    public function test_a_fixed_discount_only_gives_the_label_on_the_cards(): void
    {
        $product = $this->shopProduct();
        $this->makeDiscount(['type' => 'fixed', 'value' => 10]);

        $card = collect($this->getJson(self::STORE.'/products')->json('data'))->firstWhere('id', $product->id);

        $this->assertEquals(160, $card['price']);                   // a fixed amount comes off the order, not off a unit
        $this->assertSame('$ 10 off', $card['discount_label']);
    }

    public function test_a_fixed_discount_on_a_chosen_product_is_taken_off_every_unit_and_shows_on_the_card(): void
    {
        $product = $this->shopProduct();   // price 160
        $other = $this->shopProduct();
        $this->makeDiscount(['type' => 'fixed', 'value' => 50, 'scope' => 'products', 'targets' => [$product->id]]);

        $cards = collect($this->getJson(self::STORE.'/products')->json('data'));
        $card = $cards->firstWhere('id', $product->id);
        $this->assertEquals(110, $card['price']);                    // 160 - 50: the price people pay, shown on the card
        $this->assertSame('$ 50 off', $card['discount_label']);
        $this->assertEquals(160, $cards->firstWhere('id', $other->id)['price']);

        $line = $this->line($this->variantOf($product), 3);
        $this->assertEquals(150, $line['discount_amount']);          // 3 units x 50
        $this->assertEquals(330, $line['line_total']);               // 3 x 110
    }

    public function test_the_bigger_of_a_chosen_product_amount_and_a_store_percentage_is_shown(): void
    {
        $product = $this->shopProduct();   // price 160: 50 off beats 20 % (32)
        $this->makeDiscount(['type' => 'fixed', 'value' => 50, 'scope' => 'products', 'targets' => [$product->id]]);
        $this->makeDiscount(['name' => 'Store', 'type' => 'percent', 'value' => 20]);
        $card = collect($this->getJson(self::STORE.'/products')->json('data'))->firstWhere('id', $product->id);
        $this->assertEquals(110, $card['price']);
        $this->assertSame('$ 50 off', $card['discount_label']);

        $cheap = $this->shopProduct(['mrp' => 100, 'selling_price' => 100, 'price_tiers' => []]);   // 20 % (20) beats the 50 only on a pricier product
        $this->makeDiscount(['name' => 'Cheap', 'type' => 'fixed', 'value' => 10, 'scope' => 'products', 'targets' => [$cheap->id]]);
        $card = collect($this->getJson(self::STORE.'/products')->json('data'))->firstWhere('id', $cheap->id);
        $this->assertEquals(80, $card['price']);
        $this->assertSame('20% off', $card['discount_label']);
    }

    public function test_without_a_discount_the_cards_have_no_label_and_compare_with_the_mrp(): void
    {
        $product = $this->shopProduct();

        $card = collect($this->getJson(self::STORE.'/products')->json('data'))->firstWhere('id', $product->id);

        $this->assertEquals(160, $card['price']);
        $this->assertEquals(200, $card['compare_price']);
        $this->assertNull($card['discount_label']);
    }

    public function test_the_product_list_costs_the_same_number_of_queries_with_many_discounts(): void
    {
        foreach (range(1, 6) as $i) {
            $this->shopProduct();
        }
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE.'/products')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->getJson(self::STORE.'/products')->assertOk();   // warm the caches

        $none = $queries();
        foreach (range(1, 8) as $i) {
            $this->makeDiscount(['name' => "D$i", 'value' => $i]);
        }
        $many = $queries();

        $this->assertLessThanOrEqual($none + 2, $many, 'the discounts are read with two queries however many products the list shows');
    }

    public function test_a_discount_row_is_valid_only_for_known_types(): void
    {
        $this->assertSame(['store', 'products', 'categories', 'sub_categories'], Discount::SCOPES);
    }
}

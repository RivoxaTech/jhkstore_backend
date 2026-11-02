<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** POST /store/checkout/quote: the storefront keeps the cart, the server prices it. */
class QuoteTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    public function test_a_quote_prices_the_lines_and_the_totals_on_the_server(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // MRP 200, price 160, bulk 5+ = 152, 10+ = 140

        $res = $this->quote([$variant => 5])->assertOk();
        $line = $res->json('data.items.0');

        $this->assertSame($variant, $line['variant_id']);
        $this->assertEquals(160, $line['price']);
        $this->assertEquals(152, $line['unit_price']);
        $this->assertSame(5, $line['bulk_applied']['min_qty']);
        $this->assertSame('Buy 5+ for $ 152 each', $line['bulk_applied']['label']);
        $this->assertSame(10, $line['next_offer']['min_qty']);
        $this->assertSame(5, $line['next_offer']['add_qty']);
        $this->assertEquals(760, $line['line_total']);
        $this->assertTrue($line['can_buy']);

        $s = $res->json('data.summary');
        $this->assertEquals(1000, $s['items_total']);
        $this->assertEquals(200, $s['product_discount']);
        $this->assertEquals(40, $s['bulk_discount']);
        $this->assertEquals(760, $s['subtotal']);
        $this->assertEquals(300, $s['delivery_fee']);
        $this->assertFalse($s['delivery_free']);
        $this->assertEquals(7740, $s['amount_for_free_delivery']);
        $this->assertEquals(1060, $s['grand_total']);
        $this->assertEquals(240, $s['total_savings']);
        $this->assertEquals(round(760 * 18 / 118, 2), $s['tax_total']);
        $this->assertSame('VAT', $s['tax_label']);
        $this->assertTrue($res->json('data.can_checkout'));
        $this->assertSame([], $res->json('data.issues'));
        $this->assertSame('USD', $res->json('data.currency'));
        $this->assertSame('$', $res->json('data.currency_symbol'));
    }

    public function test_the_quote_matches_the_order_that_follows(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $quoted = $this->quote([$variant => 10])->json('data.summary.grand_total');

        $this->placeOrder($this->orderBody([$variant => 10], ['expected_total' => $quoted]))->assertCreated()->assertJsonPath('data.totals.grand_total', $quoted);
    }

    public function test_a_quote_reserves_and_stores_nothing(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->quote([$variant => 5])->assertOk();

        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertSame(0, $this->stockOf($variant)->reserved_qty);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_no_login_is_needed(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->quote([$variant => 1])->assertOk();
        $this->quote([$variant => 1], ['Authorization' => 'Bearer garbage'])->assertOk();   // a bad token is simply ignored
    }

    public function test_free_delivery_threshold_and_minimum_order(): void
    {
        $big = $this->variantOf($this->shopProduct(['mrp' => 9000, 'selling_price' => 9000, 'price_tiers' => []]));
        $this->quote([$big => 1])->assertJsonPath('data.summary.delivery_free', true)->assertJsonPath('data.summary.delivery_fee', 0)->assertJsonPath('data.summary.amount_for_free_delivery', null);

        $small = $this->variantOf($this->shopProduct());
        $this->setSetting('min_order_amount', 500);
        $this->quote([$small => 1])->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.issues.0.code', 'below_min_order')
            ->assertJsonPath('data.issues.0.message', 'The minimum order amount is $ 500. Add items worth $ 340 more.');
    }

    public function test_warnings_for_stock_and_quantity_limits(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 3], ['max_order_qty' => 5]));

        $this->quote([$variant => 4])->assertOk()
            ->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.items.0.can_buy', false)
            ->assertJsonPath('data.issues.0.code', 'insufficient_stock')->assertJsonPath('data.items.0.warnings.0.message', 'Only 3 left in stock for "'.$this->stockOf($variant)->name.'".');

        DB::table('product_variants')->where('id', $variant)->update(['stock_qty' => 0]);
        $this->quote([$variant => 1])->assertJsonPath('data.issues.0.code', 'out_of_stock')->assertJsonPath('data.items.0.in_totals', false);

        DB::table('product_variants')->where('id', $variant)->update(['stock_qty' => 100]);
        $this->quote([$variant => 6])->assertJsonPath('data.issues.0.code', 'above_max_qty');
    }

    public function test_an_unknown_option_is_reported_not_priced(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $res = $this->quote([$variant => 1, 987654 => 1])->assertOk();
        $this->assertSame(1, $res->json('data.lines_count'));
        $res->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.issues.0.variant_id', 987654)->assertJsonPath('data.issues.0.code', 'unavailable');
    }

    public function test_a_quote_is_validated(): void
    {
        $this->quote([])->assertStatus(422)->assertJsonValidationErrors('items');
        config(['shop.max_items_per_order' => 2]);
        $this->quote([[1, 1], [2, 1], [3, 1]])->assertStatus(422)->assertJsonValidationErrors('items');
        config(['shop.max_qty_per_line' => 10]);
        $this->quote([[1, 11]])->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
        $this->quote([[1, 1], [1, 2]])->assertStatus(422)->assertJsonValidationErrors('items.1.variant_id');
    }

    public function test_the_client_cannot_set_prices(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $res = $this->flushHeaders()->postJson(self::STORE.'/checkout/quote', ['items' => [['variant_id' => $variant, 'qty' => 1, 'price' => 1, 'unit_price' => 1]], 'grand_total' => 1])->assertOk();
        $this->assertEquals(460, $res->json('data.summary.grand_total'));
    }

    public function test_a_quote_costs_a_fixed_number_of_queries(): void
    {
        $lines = [];
        foreach (range(1, 6) as $i) {
            $lines[$this->variantOf($this->shopProduct())] = 2;
        }
        $this->quote([array_key_first($lines) => 1]);   // warm the settings

        DB::enableQueryLog();
        $this->quote($lines)->assertOk()->assertJsonPath('data.lines_count', 6);
        $six = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->quote([array_key_first($lines) => 1])->assertOk();
        $one = count(DB::getQueryLog());

        $this->assertSame($one, $six, 'the number of queries must not grow with the number of lines');
    }
}

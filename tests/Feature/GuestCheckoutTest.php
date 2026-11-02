<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Guest orders: nobody logs in. Contact + address + items in one request. */
class GuestCheckoutTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    // ------------------------------------------------------------------ happy path

    public function test_a_guest_places_a_cod_order_end_to_end(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // 5 x 152 (bulk) = 760, delivery 300
        $before = $this->stockOf($variant)->stock_qty;

        $res = $this->placeOrder($this->orderBody([$variant => 5], [
            'customer' => ['name' => 'Alex Morgan', 'phone' => '+1 555 010 0001', 'email' => 'Alex@Example.test'],
            'customer_note' => 'Call before delivery',
        ]))->assertCreated();
        $d = $res->json('data');

        $this->assertMatchesRegularExpression('/^ORD-'.now()->year.'-\d{6}$/', $d['order_number']);
        $this->assertSame('pending', $d['status']);
        $this->assertSame('cod', $d['payment_method']);
        $this->assertSame('Cash on delivery', $d['payment_method_label']);
        $this->assertSame('cod_pending', $d['payment_status']);
        $this->assertSame(5, $d['items_count']);
        $this->assertSame('Call before delivery', $d['customer_note']);
        $this->assertSame(now()->addDays(5)->toDateString(), $d['estimated_delivery_date']);
        $this->assertSame('+15550100001', $d['customer']['phone']);
        $this->assertSame('alex@example.test', $d['customer']['email']);

        // price breakdown: 1000 at MRP - 200 product discount - 40 bulk offer = 760; + 300 delivery = 1060
        $t = $d['totals'];
        $this->assertEquals(1000, $t['items_total']);
        $this->assertEquals(200, $t['product_discount']);
        $this->assertEquals(40, $t['bulk_discount']);
        $this->assertEquals(760, $t['subtotal']);
        $this->assertEquals(300, $t['delivery_fee']);
        $this->assertEquals(1060, $t['grand_total']);
        $this->assertEquals(round(760 * 18 / 118, 2), $t['tax_total']);
        $this->assertSame('VAT', $t['tax_label']);
        $this->assertSame('Inclusive of all taxes', $t['tax_note']);
        $this->assertEquals(240, $t['total_savings']);

        // the line is a snapshot
        $item = $d['items'][0];
        $product = DB::table('product_variants')->where('id', $variant)->first();
        $this->assertSame($product->sku, $item['sku']);
        $this->assertSame($product->name, $item['option_name']);
        $this->assertSame(5, $item['qty']);
        $this->assertEquals(200, $item['mrp']);
        $this->assertEquals(160, $item['price']);
        $this->assertEquals(152, $item['unit_price']);
        $this->assertSame(5, $item['bulk_applied']['min_qty']);
        $this->assertEquals(40, $item['bulk_discount']);
        $this->assertEquals(760, $item['line_total']);
        $this->assertEquals(18, $item['tax_rate']);

        // address snapshots, billing = shipping by default; the name and phone default to the contact's
        $this->assertSame('12 Example Street', $d['shipping_address']['line1']);
        $this->assertSame('Springfield', $d['shipping_address']['city']);
        $this->assertSame('Alex Morgan', $d['shipping_address']['name']);
        $this->assertSame('+1 555 010 0001', $d['shipping_address']['phone']);
        $this->assertSame($d['shipping_address'], $d['billing_address']);
        $this->assertSame(['pending'], collect($d['timeline'])->pluck('status')->all());

        // stock deducted at once, with a movement; payment row; the order keeps the contact as it was
        $this->assertSame($before - 5, $this->stockOf($variant)->stock_qty);
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variant, 'type' => 'sale', 'qty_change' => -5, 'balance_after' => $before - 5, 'reference_type' => 'order', 'reference_id' => $d['id']]);
        $this->assertDatabaseHas('payments', ['payable_type' => 'order', 'payable_id' => $d['id'], 'method' => 'cod', 'status' => 'cod_pending', 'amount' => 1060, 'currency' => 'USD']);
        $this->assertDatabaseHas('orders', ['id' => $d['id'], 'customer_name' => 'Alex Morgan', 'customer_phone' => '+15550100001', 'items_count' => 5]);
        $this->assertDatabaseCount('order_addresses', 2);
    }

    public function test_no_login_or_token_is_needed_and_the_email_is_optional(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $body = $this->orderBody([$variant => 1], ['customer' => ['name' => 'No Mail', 'phone' => '+1 555 010 7777']]);

        $this->placeOrder($body)->assertCreated()->assertJsonPath('data.customer.email', null);
        $this->assertNull(Customer::query()->where('phone', '+15550107777')->value('email'));
    }

    public function test_the_billing_address_can_differ_from_the_shipping_address(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1], ['billing_address' => $this->addressData(['line1' => 'Billing Office 9', 'city' => 'Capital City'])]))
            ->assertCreated()
            ->assertJsonPath('data.shipping_address.city', 'Springfield')
            ->assertJsonPath('data.billing_address.city', 'Capital City')
            ->assertJsonPath('data.billing_address.line1', 'Billing Office 9');
    }

    public function test_the_address_needs_only_a_street_and_a_city(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1], ['address' => ['line1' => '5 Main Road', 'city' => 'Springfield']]))
            ->assertCreated()->assertJsonPath('data.shipping_address.state', null)->assertJsonPath('data.shipping_address.country', 'Testland');
    }

    public function test_order_numbers_are_unique_and_readable(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $a = $this->placeOrder($this->orderBody([$variant => 1]))->json('data.order_number');
        $b = $this->placeOrder($this->orderBody([$variant => 1]))->json('data.order_number');

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{6}$/', $a);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{6}$/', $b);
    }

    public function test_selling_the_last_unit_marks_the_product_out_of_stock_and_logs_a_low_stock_alert(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 3]));

        $this->placeOrder($this->orderBody([$variant => 3]))->assertCreated();

        $this->assertSame(0, $this->stockOf($variant)->stock_qty);
        $this->assertSame(0, DB::table('products')->whereIn('id', DB::table('product_variants')->where('id', $variant)->select('product_id'))->value('is_in_stock'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.low_stock', 'subject_type' => 'ProductVariant', 'subject_id' => $variant]);
    }

    public function test_several_lines_in_one_order(): void
    {
        $a = $this->variantOf($this->shopProduct());
        $b = $this->variantOf($this->shopProduct(['mrp' => 50, 'selling_price' => 40, 'price_tiers' => []]));

        $res = $this->placeOrder($this->orderBody([$a => 1, $b => 2]))->assertCreated();   // 160 + 80 = 240 + 300 delivery
        $this->assertCount(2, $res->json('data.items'));
        $this->assertEquals(540, $res->json('data.totals.grand_total'));
        $this->assertSame(3, $res->json('data.items_count'));
    }

    // ------------------------------------------------------------------ validation

    public function test_the_contact_and_the_address_are_required_and_validated(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $post = fn (array $override) => $this->placeOrder($this->orderBody([$variant => 1], $override));

        $post(['customer' => null])->assertStatus(422)->assertJsonValidationErrors('customer');
        $post(['customer' => ['name' => '', 'phone' => '']])->assertStatus(422)->assertJsonValidationErrors(['customer.name', 'customer.phone']);
        $post(['customer' => ['name' => 'Alex', 'phone' => 'not-a-phone']])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $post(['customer' => ['name' => 'Alex', 'phone' => '12345']])->assertStatus(422)->assertJsonValidationErrors('customer.phone');          // too short
        $post(['customer' => ['name' => 'Alex', 'phone' => '+1 555 010 99999999999']])->assertStatus(422)->assertJsonValidationErrors('customer.phone'); // too long
        $post(['customer' => ['name' => 'Alex', 'phone' => '+1 555 010 0100', 'email' => 'nope']])->assertStatus(422)->assertJsonValidationErrors('customer.email');
        $post(['address' => null])->assertStatus(422)->assertJsonValidationErrors('address');
        $post(['address' => ['line1' => '', 'city' => '']])->assertStatus(422)->assertJsonValidationErrors(['address.line1', 'address.city']);
        $post(['address' => $this->addressData(['postcode' => '<script>'])])->assertStatus(422)->assertJsonValidationErrors('address.postcode');
        $post(['customer_note' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('customer_note');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());   // a refused order leaves no contact behind
    }

    public function test_the_phone_accepts_common_formats(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        foreach (['+49 170 1234567', '0170-1234567', '(555) 010-1234', '00441234567890'] as $i => $phone) {
            $this->placeOrder($this->orderBody([$variant => 1], ['customer' => ['name' => 'Alex', 'phone' => $phone]]))->assertCreated();
        }
        $this->assertSame(4, Customer::count());
    }

    public function test_the_items_have_limits(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        config(['shop.max_items_per_order' => 2, 'shop.max_qty_per_line' => 20]);

        $this->placeOrder($this->orderBody([]))->assertStatus(422)->assertJsonValidationErrors('items');
        $this->placeOrder($this->orderBody([[1, 1], [2, 1], [3, 1]]))->assertStatus(422)->assertJsonValidationErrors('items')
            ->assertJsonPath('errors.items.0', 'An order can have at most 2 different items.');
        $this->placeOrder($this->orderBody([$variant => 21]))->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
        $this->placeOrder($this->orderBody([$variant => 0]))->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
        $this->placeOrder($this->orderBody([[$variant, 1], [$variant, 2]]))->assertStatus(422)->assertJsonValidationErrors('items.1.variant_id');
        $this->placeOrder($this->orderBody([['x', 1]]))->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');

        $this->assertSame(0, Order::count());
    }

    public function test_the_products_own_quantity_limits_apply(): void
    {
        $variant = $this->variantOf($this->shopProduct([], ['min_order_qty' => 2, 'max_order_qty' => 4]));

        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('lines.0.code', 'below_min_qty');
        $this->placeOrder($this->orderBody([$variant => 5]))->assertStatus(422)->assertJsonPath('lines.0.code', 'above_max_qty');
        $this->placeOrder($this->orderBody([$variant => 2]))->assertCreated();
    }

    public function test_an_unknown_or_hidden_option_cannot_be_ordered(): void
    {
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);

        $this->placeOrder($this->orderBody([999999 => 1]))->assertStatus(422)->assertJsonPath('code', 'cart_invalid')->assertJsonPath('lines.0.code', 'unavailable');

        $product->update(['status' => 'draft']);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('lines.0.code', 'unavailable');
        $this->assertSame(0, Order::count());
    }

    public function test_only_cash_on_delivery_exists_and_it_has_a_limit_and_a_per_option_switch(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // 5 x 152 + 300 = 1060

        $this->setSetting('cod_max_amount', 1000);
        $this->placeOrder($this->orderBody([$variant => 5]))->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed')
            ->assertJsonPath('message', 'Cash on delivery is available up to $ 1,000 per order. Please reduce your order.');
        $this->setSetting('cod_max_amount', 0);   // 0 = no limit

        DB::table('product_variants')->where('id', $variant)->update(['is_cod_available' => false]);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed');
        DB::table('product_variants')->update(['is_cod_available' => null]);

        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
    }

    public function test_there_is_a_minimum_order_amount(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // subtotal 160
        $this->setSetting('min_order_amount', 500);

        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'below_min_order')
            ->assertJsonPath('message', 'The minimum order amount is $ 500. Add items worth $ 340 more.');

        $this->setSetting('min_order_amount', 160);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
    }

    public function test_not_enough_stock_is_refused_with_the_line_that_needs_attention(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 4]));

        $res = $this->placeOrder($this->orderBody([$variant => 6]))->assertStatus(422);
        $res->assertJsonPath('code', 'cart_invalid')->assertJsonPath('lines.0.variant_id', $variant)->assertJsonPath('lines.0.code', 'insufficient_stock');

        $this->assertSame(0, Order::count());
        $this->assertSame(4, $this->stockOf($variant)->stock_qty);
    }

    // ------------------------------------------------------------------ prices

    public function test_the_total_the_customer_saw_must_still_be_true(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $seen = $this->quote([$variant => 1])->json('data.summary.grand_total');   // 160 + 300

        DB::table('product_variants')->where('id', $variant)->update(['selling_price' => 170]);

        $this->placeOrder($this->orderBody([$variant => 1], ['expected_total' => $seen]))->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('current_total', 470);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());

        $this->placeOrder($this->orderBody([$variant => 1], ['expected_total' => 470]))->assertCreated()->assertJsonPath('data.totals.grand_total', 470);
    }

    public function test_the_price_comes_from_the_server_never_from_the_client(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $body = $this->orderBody([$variant => 1], ['unit_price' => 1, 'grand_total' => 1]);
        $body['items'][0]['price'] = 1;
        $this->placeOrder($body)->assertCreated()->assertJsonPath('data.totals.grand_total', 460);
    }

    public function test_free_delivery_and_tax_exclusive_prices(): void
    {
        $variant = $this->variantOf($this->shopProduct([], ['max_order_qty' => 100]));

        $this->placeOrder($this->orderBody([$variant => 60]))->assertStatus(422);   // more than the stock of 50
        $res = $this->placeOrder($this->orderBody([$variant => 50]))->assertCreated();   // 50 x 140 = 7000 (< 8500): delivery charged
        $this->assertEquals(300, $res->json('data.totals.delivery_fee'));

        $big = $this->variantOf($this->shopProduct(['mrp' => 9000, 'selling_price' => 9000, 'price_tiers' => []]));
        $this->placeOrder($this->orderBody([$big => 1]))->assertCreated()->assertJsonPath('data.totals.delivery_fee', 0);

        $this->setSetting('tax_inclusive', false);
        $fresh = $this->variantOf($this->shopProduct());
        $res = $this->placeOrder($this->orderBody([$fresh => 1]))->assertCreated();   // 160 + tax 18% = 28.8 + 300
        $this->assertEquals(488.8, $res->json('data.totals.grand_total'));
        $this->assertSame('Tax is added to the total', $res->json('data.totals.tax_note'));
    }

    // ------------------------------------------------------------------ double submit and overselling

    public function test_the_same_idempotency_key_returns_the_same_order(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $body = $this->orderBody([$variant => 2]);
        $key = 'order-key-0001';

        $first = $this->placeOrder($body, ['Idempotency-Key' => $key])->assertCreated();
        $second = $this->placeOrder($body, ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame(1, Order::count());
        $this->assertSame(48, $this->stockOf($variant)->stock_qty);   // deducted once
        $this->assertSame(1, Customer::first()->orders_count);
    }

    public function test_an_idempotency_key_of_another_customer_does_not_replay_their_order(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $key = 'shared-key-0001';

        $this->placeOrder($this->orderBody([$variant => 1]), ['Idempotency-Key' => $key])->assertCreated();
        $this->placeOrder($this->orderBody([$variant => 1]), ['Idempotency-Key' => $key])->assertCreated();   // another phone: a new order

        $this->assertSame(2, Order::count());
    }

    public function test_a_bad_idempotency_key_is_refused(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1]), ['Idempotency-Key' => 'x'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_invalid');
    }

    public function test_the_last_unit_can_only_be_sold_once(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 1]));

        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'cart_invalid')->assertJsonPath('lines.0.code', 'out_of_stock');

        $this->assertSame(0, $this->stockOf($variant)->stock_qty);
        $this->assertSame(1, Order::count());
    }

    public function test_stock_never_goes_below_zero_however_many_customers_check_out(): void
    {
        $variant = $this->variantOf($this->shopProduct(['stock' => 5], ['max_order_qty' => 5]));

        $placed = 0;
        foreach (range(1, 6) as $i) {
            $placed += $this->placeOrder($this->orderBody([$variant => 2]))->status() === 201 ? 1 : 0;
        }

        $this->assertSame(2, $placed);                                        // 5 units -> only two customers can have 2
        $this->assertSame(1, $this->stockOf($variant)->stock_qty);            // 5 - 4, never negative
        $this->assertSame(4, (int) DB::table('order_items')->sum('qty'));
        $this->assertSame(-4, (int) DB::table('inventory_movements')->where('type', 'sale')->sum('qty_change'));
    }

    public function test_checkout_locks_the_stock_rows_before_it_reads_them(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        DB::enableQueryLog();
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $lockAt = $queries->search(fn ($q) => str_contains($q, 'product_variants') && str_contains(strtolower($q), 'for update'));
        $decrementAt = $queries->search(fn ($q) => str_starts_with($q, 'update `product_variants`'));

        $this->assertNotFalse($lockAt, 'the stock rows must be read with FOR UPDATE');
        $this->assertNotFalse($decrementAt);
        $this->assertLessThan($decrementAt, $lockAt);
    }

    public function test_a_failed_order_changes_nothing(): void
    {
        $good = $this->variantOf($this->shopProduct());
        $bad = $this->variantOf($this->shopProduct(['stock' => 0]));

        $this->placeOrder($this->orderBody([$good => 2, $bad => 1]))->assertStatus(422);

        $this->assertSame(50, $this->stockOf($good)->stock_qty);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());
        $this->assertDatabaseCount('inventory_movements', 1);   // only the 'initial' movement of the product that has stock
    }

    // ------------------------------------------------------------------ honeypot

    public function test_a_filled_honeypot_field_is_refused_quietly(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1], ['website' => 'http://spam.example']))
            ->assertStatus(422)->assertJsonPath('code', 'rejected')->assertJsonMissingPath('errors');
        $this->assertSame(0, Order::count());

        $this->placeOrder($this->orderBody([$variant => 1], ['website' => '']))->assertCreated();   // an empty hidden field is what a person sends
    }

    public function test_the_honeypot_field_name_can_be_changed(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        config(['shop.honeypot_field' => 'fax']);

        $this->placeOrder($this->orderBody([$variant => 1], ['fax' => 'x']))->assertStatus(422)->assertJsonPath('code', 'rejected');
        $this->placeOrder($this->orderBody([$variant => 1], ['website' => 'x']))->assertCreated();
    }
}

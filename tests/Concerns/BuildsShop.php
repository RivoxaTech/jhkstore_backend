<?php

namespace Tests\Concerns;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Delivery\Models\DeliveryZone;
use App\Domain\Order\Models\Order;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponTarget;
use App\Domain\Promotion\Models\Discount;
use App\Domain\Promotion\Models\DiscountTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/** Fixtures for the quote, guest checkout and order tests: products with bulk prices, order payloads and the calls. */
trait BuildsShop
{
    use BuildsCatalog;

    private const STORE = '/api/v1/store';
    private const ADMIN = '/api/v1/admin';

    private Category $shopCategory;

    protected function seedShop(): void
    {
        $this->seedCatalog();
        $this->shopCategory = $this->makeCategory('Shop Category');
    }

    /** Contact of a guest. Every call gets its own phone number unless one is given. */
    protected function contact(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge(['name' => 'Alex Morgan', 'phone' => sprintf('+1 555 010 %04d', $n), 'email' => "alex$n@example.test"], $overrides);
    }

    protected function addressData(array $overrides = []): array
    {
        return $overrides + [
            'line1' => '12 Example Street', 'area' => 'Downtown', 'landmark' => 'Near the park',
            'city' => 'Springfield', 'state' => 'Sample State', 'postcode' => '12345', 'country' => 'Testland',
        ];
    }

    /** The body of POST /store/orders. `$items` = [[variant_id, qty], ...] or [variant_id => qty]. */
    protected function orderBody(array $items, array $overrides = []): array
    {
        $lines = [];
        foreach ($items as $key => $value) {
            $lines[] = is_array($value) ? ['variant_id' => $value[0], 'qty' => $value[1]] : ['variant_id' => $key, 'qty' => $value];
        }

        return array_merge(['customer' => $this->contact(), 'address' => $this->addressData(), 'items' => $lines], $overrides);
    }

    /**
     * A product with ONE option: MRP 200 / price 160, bulk prices "5+ for 152" and "10+ for 140", 50 units in stock.
     * `$variant` overrides the option, `$product` the product (e.g. min_order_qty, max_order_qty, tax_rate, status).
     */
    protected function shopProduct(array $variant = [], array $product = []): Product
    {
        static $n = 0;
        $n++;

        return $this->makeProduct($this->shopCategory, $product + [
            'name' => "Shop Item $n",
            'sku' => "SHOP-$n",
            'variants' => [array_merge([
                'name' => "Shop Item $n (15ml)", 'sku' => "SHOP-$n-V", 'is_default' => true, 'mrp' => 200, 'selling_price' => 160,
                'stock' => 50,
                'price_tiers' => [['min_qty' => 5, 'price' => 152], ['min_qty' => 10, 'price' => 140]],
            ], $variant)],
        ]);
    }

    protected function variantOf(Product $product): int
    {
        return $product->variants()->value('id');
    }

    protected function stockOf(int $variantId): ProductVariant
    {
        return ProductVariant::query()->findOrFail($variantId);
    }

    protected function setSetting(string $key, mixed $value): void
    {
        app(\App\Domain\Support\Settings::class)->set([$key => $value]);
    }

    /** Logs in as an admin with every permission (the `admin` role switched fully on). */
    protected function asAdmin(string $role = 'admin'): Admin
    {
        return $this->actingAsAdmin($role);
    }

    /** Places a COD order as a guest for a new product; returns the stored order. `$contact` overrides the guest's contact. */
    protected function orderFor(array $contact = [], int $qty = 1, array $variant = []): Order
    {
        $variantId = $this->variantOf($this->shopProduct($variant));
        $number = $this->placeOrder($this->orderBody([$variantId => $qty], ['customer' => $this->contact($contact)]))->assertCreated()->json('data.order_number');

        return Order::query()->where('order_number', $number)->firstOrFail();
    }

    // ------------------------------------------------------------------ promotions, zones, bank transfer

    /** An automatic discount (default: 10 % on the whole store). `targets` = ids for a products / categories / sub_categories scope. */
    protected function makeDiscount(array $o = []): Discount
    {
        $targets = $o['targets'] ?? [];
        unset($o['targets']);
        $discount = Discount::create($o + ['name' => 'Sale', 'type' => 'percent', 'value' => 10, 'scope' => 'store', 'is_active' => true, 'priority' => 0]);
        $type = Discount::TARGET_TYPE[$discount->scope] ?? null;
        foreach ($targets as $id) {
            DiscountTarget::create(['discount_id' => $discount->id, 'type' => $type, 'target_id' => $id]);
        }

        return $discount->load('targets');
    }

    /** A coupon (default: SAVE10, 10 % on the whole store). */
    protected function makeCoupon(array $o = []): Coupon
    {
        $targets = $o['targets'] ?? [];
        unset($o['targets']);
        $coupon = Coupon::create($o + ['code' => 'SAVE10', 'name' => 'Save 10', 'type' => 'percent', 'value' => 10, 'scope' => 'store', 'is_active' => true]);
        $type = Coupon::TARGET_TYPE[$coupon->scope] ?? null;
        foreach ($targets as $id) {
            CouponTarget::create(['coupon_id' => $coupon->id, 'type' => $type, 'target_id' => $id]);
        }

        return $coupon->load('targets');
    }

    protected function makeZone(array $o = []): DeliveryZone
    {
        static $n = 0;
        $n++;

        return DeliveryZone::create($o + ['name' => "Zone $n", 'match_type' => 'city', 'match_values' => ['Springfield'], 'fee' => 5, 'sort_order' => $n, 'is_active' => true]);
    }

    /** A valid payment proof picture. */
    protected function proofFile(string $name = 'proof.png', int $size = 300): UploadedFile
    {
        return UploadedFile::fake()->image($name, $size, $size);
    }

    /** Places a bank transfer order as a guest; returns the stored order. */
    protected function bankOrder(array $contact = [], int $qty = 1): Order
    {
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'Test Bank', 'title' => 'Test Shop', 'number' => '123456', 'iban' => '', 'active' => true]]);
        $variantId = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variantId => $qty], ['customer' => $this->contact($contact), 'payment_method' => 'bank_transfer']))->assertCreated()->json('data.order_number');

        return Order::query()->where('order_number', $number)->firstOrFail();
    }

    protected function uploadProof(Order $order, ?string $phone = null, ?UploadedFile $file = null, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->post(self::STORE."/orders/{$order->order_number}/payment-proof?phone=".urlencode($phone ?? $order->customer_phone), ['proof' => $file ?? $this->proofFile()] + $extra, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ calls

    protected function quote(array $items, array $headers = [], array $extra = []): TestResponse
    {
        $lines = [];
        foreach ($items as $key => $value) {
            $lines[] = is_array($value) ? ['variant_id' => $value[0], 'qty' => $value[1]] : ['variant_id' => $key, 'qty' => $value];
        }

        return $this->flushHeaders()->withHeaders($headers)->postJson(self::STORE.'/checkout/quote', ['items' => $lines] + $extra);
    }

    protected function placeOrder(array $body = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withHeaders($headers)->postJson(self::STORE.'/orders', $body);
    }

    protected function lookup(string $orderNumber, string $phone): TestResponse
    {
        return $this->flushHeaders()->getJson(self::STORE.'/orders/'.$orderNumber.'?phone='.urlencode($phone));
    }
}

<?php

namespace App\Domain\Order\Actions;

use App\Domain\Cart\CartPricing;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricedLine;
use App\Domain\Catalog\Actions\RefreshProductSummary;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductImages;
use App\Domain\Customer\Actions\UpsertCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Phone;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderAddress;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Domain\Support\Settings;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest checkout: turns what the storefront sent (contact, address, option ids and quantities) into an order. Nobody logs in.
 * Everything happens in ONE database transaction:
 *
 *   1. lock the stock rows of the ordered options (FOR UPDATE, always in option order, so two checkouts can never deadlock or oversell)
 *   2. look for an earlier order with the same Idempotency-Key (a double tap waits at step 1, then finds the first order here)
 *   3. price everything again from live data (CartPricing) and re-validate it
 *      (stock, quantity limits, minimum order, cash-on-delivery limit, the total the customer saw)
 *   4. find or create the customer by normalised phone, write order + lines + address snapshots + payment + history, deduct the stock (movement `sale`)
 *
 * Stock is DEDUCTED when the order is placed (`stock_qty - qty`), not reserved: a COD order is a real order from the first minute.
 * `reserved_qty` stays free for online payments later (hold while the payment is pending). Cancelling puts the stock back.
 */
class PlaceOrder
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly Settings $settings,
        private readonly UpsertCustomer $customers,
        private readonly RefreshProductSummary $refreshProduct,
    ) {}

    /**
     * @param  array{customer: array{name:string, phone:string, email?:?string}, address: array, billing_address?: array, items: list<array{variant_id:int, qty:int}>, customer_note?:?string, expected_total?:numeric}  $input
     * @return array{0: Order, 1: bool}  the order and whether it is an earlier order returned for the same idempotency key
     */
    public function handle(array $input, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->replay($input['customer']['phone'], $idempotencyKey))) {
            return [$existing, true];
        }

        try {
            $order = DB::transaction(fn () => $this->place($input, $idempotencyKey), 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey && ($existing = $this->replay($input['customer']['phone'], $idempotencyKey))) {
                return [$existing, true];   // two requests with the same key raced: the loser gets the winner's order
            }
            throw $e;
        }

        return [$order, ! $order->wasRecentlyCreated];
    }

    private function place(array $input, ?string $key): Order
    {
        $variantIds = collect($input['items'])->pluck('variant_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $stocks = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($key && ($existing = $this->replay($input['customer']['phone'], $key))) {
            return $existing;   // the same request arrived while the first one was running
        }

        $priced = $this->pricing->forItems($input['items'], $stocks);
        $this->validate($priced, $input);

        $shipping = $input['address'];
        $billing = $input['billing_address'] ?? $shipping;
        $customer = $this->customers->handle([
            'name' => $input['customer']['name'], 'phone' => $input['customer']['phone'],
            'email' => $input['customer']['email'] ?? null, 'city' => $shipping['city'] ?? null,
        ]);

        $order = $this->createOrder($customer, $priced, $shipping, $billing, $input, $key);
        $this->createItems($order, $priced);
        $this->deductStock($order, $priced, $stocks);
        $this->customers->recordOrder($customer, $order->grand_total);

        foreach (collect($priced->countedLines())->map(fn (PricedLine $l) => $l->product)->unique('id') as $product) {
            $this->refreshProduct->handle($product);
        }

        return $order->load(['items', 'addresses', 'history', 'payment']);
    }

    /** The order the same customer (phone) already placed with this key. */
    private function replay(string $phone, string $key): ?Order
    {
        $customerId = Customer::query()->where('phone', Phone::normalise($phone))->value('id');

        return $customerId
            ? Order::query()->where('customer_id', $customerId)->where('idempotency_key', $key)->first()?->load(['items', 'addresses', 'history', 'payment'])
            : null;
    }

    private function validate(PricedCart $priced, array $input): void
    {
        if ($priced->countedLines() === [] || $priced->hasBlockingIssue()) {
            $lines = [];
            foreach ($priced->unknownVariantIds as $id) {
                $lines[] = ['variant_id' => $id, 'code' => 'unavailable', 'message' => 'One of the items is no longer available.'];
            }
            foreach ($priced->lines as $line) {
                foreach ($line->issues as $issue) {
                    if ($issue['blocking']) {
                        $lines[] = ['variant_id' => $line->variant->id, 'code' => $issue['code'], 'message' => $issue['message']];
                    }
                }
            }
            throw new CommerceException('cart_invalid', $lines[0]['message'] ?? 'Your order has no items.', 'items', 422, ['lines' => $lines]);
        }

        if (! $priced->meetsMinimum()) {
            $min = $this->pricing->fmt($priced->minOrderAmount);
            $more = $this->pricing->fmt($priced->minOrderAmount - $priced->subtotal);
            throw new CommerceException('below_min_order', "The minimum order amount is $min. Add items worth $more more.", 'items');
        }

        if (isset($input['expected_total']) && $this->settings->cents($input['expected_total']) !== $priced->grandTotal) {
            throw new CommerceException('price_changed', 'The prices have changed. Please check the new total.', 'expected_total', 409, [
                'expected_total' => (float) $input['expected_total'], 'current_total' => $this->settings->money($priced->grandTotal),
            ]);
        }

        // payment: only cash on delivery exists today; online methods get their own branch here later
        $codMax = $this->settings->codMaxCents();
        if ($codMax > 0 && $priced->grandTotal > $codMax) {
            throw new CommerceException('cod_not_allowed', 'Cash on delivery is available up to '.$this->pricing->fmt($codMax).' per order. Please reduce your order.', 'items');
        }
        foreach ($priced->countedLines() as $line) {
            if (! ($line->variant->is_cod_available ?? $line->product->is_cod_available)) {
                throw new CommerceException('cod_not_allowed', 'Cash on delivery is not available for "'.($line->variant->name ?: $line->product->name).'".', 'items');
            }
        }
    }

    private function createOrder(Customer $customer, PricedCart $priced, array $shipping, array $billing, array $input, ?string $key): Order
    {
        $money = fn (int $cents) => $this->settings->money($cents);

        $order = Order::create([
            'order_number' => 'TMP-'.Str::random(20),   // replaced below, once the id exists
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::CodPending,
            'fulfillment_status' => 'unfulfilled',
            'payment_method' => PaymentMethod::Cod->value,
            'items_count' => $priced->unitsCount,
            'mrp_total' => $money($priced->itemsTotal),
            'product_discount_total' => $money($priced->productDiscount),
            'bulk_discount_total' => $money($priced->bulkDiscount),
            'items_subtotal' => $money($priced->subtotal),
            'shipping_fee' => $money($priced->deliveryFee),
            'tax_total' => $money($priced->taxTotal),
            'tax_inclusive' => $priced->taxInclusive,
            'grand_total' => $money($priced->grandTotal),
            'customer_note' => trim((string) ($input['customer_note'] ?? '')) ?: null,
            'estimated_delivery_date' => now()->addDays((int) $this->settings->get('delivery_days'))->toDateString(),
            'placed_at' => now(),
            'source' => 'web',
            'idempotency_key' => $key,
        ]);
        $order->forceFill(['order_number' => sprintf('%s-%s-%06d', strtoupper((string) $this->settings->get('order_number_prefix')), now()->format('Y'), $order->id)])->save();

        foreach (['shipping' => $shipping, 'billing' => $billing] as $type => $address) {
            OrderAddress::create(['order_id' => $order->id, 'type' => $type]
                + collect(OrderAddress::FIELDS)->mapWithKeys(fn ($f) => [$f => $address[$f] ?? ($f === 'country' ? (string) $this->settings->get('store_country') : null)])->all());
        }

        OrderStatusHistory::create([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending->value, 'note' => 'Order placed',
            'changed_by_type' => 'customer', 'changed_by_id' => $customer->id, 'is_customer_visible' => true,
        ]);

        Payment::create([
            'payable_type' => Payment::ORDER, 'payable_id' => $order->id, 'method' => PaymentMethod::Cod->value,
            'amount' => $money($priced->grandTotal), 'currency' => $this->settings->currencyCode(), 'status' => Payment::COD_PENDING,
        ]);

        return $order;
    }

    private function createItems(Order $order, PricedCart $priced): void
    {
        $money = fn (int $cents) => $this->settings->money($cents);

        foreach ($priced->countedLines() as $line) {
            $variant = $line->variant;
            $product = $line->product;
            $own = ProductImages::gallery($product, $variant->id)[0] ?? null;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $product->name,
                'option_name' => $variant->name,
                'image_url' => $own['medium_url'] ?? ProductImages::primary($product)['image_url'],
                'mrp' => $money($line->mrp),
                'base_price' => $money($line->basePrice),
                'unit_price' => $money($line->unitPrice),
                'bulk_min_qty' => $line->bulk['min_qty'] ?? null,
                'qty' => $line->qty,
                'discount' => $money($line->productDiscount() + $line->bulkDiscount()),
                'bulk_discount' => $money($line->bulkDiscount()),
                'tax_rate' => $line->taxRate,
                'tax_amount' => $money($line->tax()),
                'line_total' => $money($line->lineTotal()),
            ]);
        }
    }

    /** Takes the units off the (locked) stock rows and logs one `sale` movement per option. A low-stock alert is logged when it runs low. */
    private function deductStock(Order $order, PricedCart $priced, Collection $stocks): void
    {
        foreach ($priced->countedLines() as $line) {
            $stock = $stocks->get($line->variant->id);
            if ($stock->stock_qty < $line->qty) {
                throw new CommerceException('insufficient_stock', 'Only '.max(0, $stock->stock_qty).' left in stock.', 'items');   // cannot happen after the check; the last guard against overselling
            }

            $stock->stock_qty -= $line->qty;
            $stock->save();

            InventoryMovement::create([
                'variant_id' => $line->variant->id, 'type' => 'sale', 'qty_change' => -$line->qty,
                'balance_after' => $stock->stock_qty, 'reference_type' => 'order', 'reference_id' => $order->id,
                'note' => "Order {$order->order_number}",
            ]);

            if ($stock->available() <= $stock->low_stock_threshold) {
                Audit::log(null, 'inventory.low_stock', $line->variant, [
                    'available' => $stock->available(), 'threshold' => $stock->low_stock_threshold,
                ]);
            }
        }
    }
}

<?php

namespace App\Domain\Order\Actions;

use App\Domain\Cart\Actions\ManageCart;
use App\Domain\Cart\CartPricing;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricedLine;
use App\Domain\Cart\PricingContext;
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
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponUsage;
use App\Domain\Support\Settings;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest checkout: turns what the storefront sent (contact, address, option ids and quantities, or a cart token) into an order. Nobody logs in.
 * Everything happens in ONE database transaction:
 *
 *   1. lock the cart (when a cart token is used), then the stock rows of the ordered options (FOR UPDATE, always in option order, so two
 *      checkouts can never deadlock or oversell), then the coupon row (so two orders cannot take the last use of a coupon together)
 *   2. look for an earlier order with the same Idempotency-Key (a double tap waits at step 1, then finds the first order here)
 *   3. price everything again from live data (CartPricing) and re-validate it
 *      (stock, quantity limits, coupon, delivery zone, minimum order, payment method, the total the customer saw)
 *   4. find or create the customer by normalised phone, write order + lines + address snapshots + payment + history, deduct the stock (movement `sale`),
 *      write the coupon use, empty the cart
 *
 * Stock is DEDUCTED when the order is placed (`stock_qty - qty`), not reserved: an order is a real order from the first minute.
 * Cancelling or rejecting puts the stock back and gives the coupon use back (ChangeOrderStatus).
 * Payment: cash on delivery (collected when delivered) or bank transfer (the customer uploads a screenshot, the admin verifies it).
 * Delivery: the fee follows the store's method; with `owner_confirms` the order is placed with the fee unknown (`delivery_fee_pending`).
 */
class PlaceOrder
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly Settings $settings,
        private readonly UpsertCustomer $customers,
        private readonly RefreshProductSummary $refreshProduct,
        private readonly ManageCart $carts,
    ) {}

    /**
     * @param  array{customer: array{name:string, phone:string, email?:?string}, address: array, billing_address?: array, items?: list<array{variant_id:int, qty:int}>, cart_token?:?string, coupon_code?:?string, payment_method?:?string, customer_note?:?string, expected_total?:numeric}  $input
     * @return array{0: Order, 1: bool}  the order and whether it is an earlier order returned for the same idempotency key
     */
    public function handle(array $input, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->replay($input['customer']['phone'], $idempotencyKey))) {
            return [$existing, true];
        }

        $this->checkStoreAndPayment($input['payment_method'] ?? PaymentMethod::Cod->value);

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

    /** A closed store takes no orders (the quote still works); a payment method the store switched off cannot be chosen. */
    private function checkStoreAndPayment(string $method): void
    {
        if (! (bool) $this->settings->get('store_open')) {
            $message = trim((string) $this->settings->get('store_closed_message'));
            throw new CommerceException('store_closed', $message !== '' ? $message : 'The store is closed right now. Please try again later.', 'store');
        }

        $enabled = match ($method) {
            PaymentMethod::Cod->value => (bool) $this->settings->get('pay_cod_enabled'),
            PaymentMethod::BankTransfer->value => (bool) $this->settings->get('pay_bank_enabled'),
            default => false,
        };
        if (! $enabled) {
            throw new CommerceException('payment_method_unavailable', 'This payment method is not available. Please choose another one.', 'payment_method');
        }
    }

    private function place(array $input, ?string $key): Order
    {
        $cart = null;
        if (empty($input['items']) && ! empty($input['cart_token'])) {
            $cart = Cart::query()->where('token', $input['cart_token'])->active()->lockForUpdate()->first();
            $rows = $cart ? $cart->items()->get() : collect();
            if ($rows->isEmpty()) {
                throw new CommerceException('cart_invalid', 'Your cart is empty.', 'cart_token', 422, ['lines' => []]);
            }
            $input['items'] = $rows->map(fn ($r) => ['variant_id' => $r->variant_id, 'qty' => $r->qty])->all();
            $input['coupon_code'] = $input['coupon_code'] ?? $cart->coupon_code;
        }

        $variantIds = collect($input['items'])->pluck('variant_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $stocks = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($key && ($existing = $this->replay($input['customer']['phone'], $key))) {
            return $existing;   // the same request arrived while the first one was running
        }

        $shipping = $input['address'];
        $billing = $input['billing_address'] ?? $shipping;
        $method = PaymentMethod::from($input['payment_method'] ?? PaymentMethod::Cod->value);

        $context = new PricingContext(
            couponCode: $input['coupon_code'] ?? null,
            address: Arr::only($shipping, ['city', 'area', 'postcode']),
            phone: Phone::normalise($input['customer']['phone']),
            lockCoupon: true,   // after the stock rows: every order locks in the same order
        );
        $priced = $this->pricing->forItems($input['items'], $stocks, $context);
        $this->validate($priced, $input, $method);

        $customer = $this->customers->handle([
            'name' => $input['customer']['name'], 'phone' => $input['customer']['phone'],
            'email' => $input['customer']['email'] ?? null, 'city' => $shipping['city'] ?? null,
        ]);

        $order = $this->createOrder($customer, $priced, $shipping, $billing, $input, $key, $method);
        $this->createItems($order, $priced);
        $this->deductStock($order, $priced, $stocks);
        $this->useCoupon($order, $priced, $customer);
        $this->customers->recordOrder($customer, $order->grand_total);

        foreach (collect($priced->countedLines())->map(fn (PricedLine $l) => $l->product)->unique('id') as $product) {
            $this->refreshProduct->handle($product);
        }
        if ($cart) {
            $this->carts->empty($cart);   // the cart has become an order
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

    private function validate(PricedCart $priced, array $input, PaymentMethod $method): void
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

        if ($priced->couponRequested && $priced->couponError) {
            throw new CommerceException('coupon_invalid', $priced->couponError['message'], 'coupon_code', 422, ['reason' => $priced->couponError['code'], 'coupon_error' => $priced->couponError]);
        }

        if ($priced->deliveryUnavailable) {
            throw new CommerceException('delivery_not_available', 'Sorry, we do not deliver to this address.', 'address');
        }

        if (! $priced->meetsMinimum()) {
            $min = $this->pricing->fmt($priced->minOrderAmount);
            $more = $this->pricing->fmt($priced->minOrderAmount - $priced->subtotalBeforeCoupon);
            throw new CommerceException('below_min_order', "The minimum order amount is $min. Add items worth $more more.", 'items');
        }

        if (isset($input['expected_total']) && $this->settings->cents($input['expected_total']) !== $priced->grandTotal) {
            throw new CommerceException('price_changed', 'The prices have changed. Please check the new total.', 'expected_total', 409, [
                'expected_total' => (float) $input['expected_total'], 'current_total' => $this->settings->money($priced->grandTotal),
            ]);
        }

        // cash on delivery has a limit and can be switched off per product; bank transfer has neither
        if ($method === PaymentMethod::Cod) {
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
    }

    private function createOrder(Customer $customer, PricedCart $priced, array $shipping, array $billing, array $input, ?string $key, PaymentMethod $method): Order
    {
        $money = fn (int $cents) => $this->settings->money($cents);
        $bank = $method === PaymentMethod::BankTransfer;
        $couponUsed = $priced->couponId !== null && ($priced->couponDiscount > 0 || $priced->couponFreeDelivery);

        $order = Order::create([
            'order_number' => 'TMP-'.Str::random(20),   // replaced below, once the id exists
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'status' => OrderStatus::Pending,
            'payment_status' => $bank ? PaymentStatus::AwaitingPayment : PaymentStatus::CodPending,
            'fulfillment_status' => 'unfulfilled',
            'payment_method' => $method->value,
            'items_count' => $priced->unitsCount,
            'mrp_total' => $money($priced->itemsTotal),
            'product_discount_total' => $money($priced->productDiscount),
            'bulk_discount_total' => $money($priced->bulkDiscount),
            'discount_total' => $money($priced->discountTotal),
            'coupon_discount' => $money($priced->couponDiscount),
            'coupon_id' => $couponUsed ? $priced->couponId : null,
            'coupon_code' => $couponUsed ? $priced->couponCode : null,
            'items_subtotal' => $money($priced->subtotal),
            'shipping_fee' => $priced->deliveryPending ? null : $money($priced->deliveryFee),
            'delivery_fee_pending' => $priced->deliveryPending,
            'delivery_zone_id' => $priced->deliveryZoneId,
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
            'payable_type' => Payment::ORDER, 'payable_id' => $order->id, 'method' => $method->value,
            'amount' => $money($priced->grandTotal), 'currency' => $this->settings->currencyCode(),
            'status' => $bank ? Payment::AWAITING_PROOF : Payment::COD_PENDING, 'proof_status' => Payment::PROOF_NONE,
            // what the customer was told to pay into: later edits of the settings must not change this order
            'bank_snapshot' => $bank ? ['accounts' => $this->settings->publicAccounts(), 'instructions' => (string) $this->settings->get('pay_bank_instructions')] : null,
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
                'discount_amount' => $money($line->discount),
                'discount_source' => $line->savingSource(),
                'discount_source_id' => $line->discountSourceId,
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

    /** Writes the coupon use. The coupon row is locked by this transaction (see place()), so the counter cannot be raced. */
    private function useCoupon(Order $order, PricedCart $priced, Customer $customer): void
    {
        if ($order->coupon_id === null) {
            return;
        }

        CouponUsage::create([
            'coupon_id' => $order->coupon_id, 'order_id' => $order->id, 'customer_id' => $customer->id, 'phone' => $customer->phone,
            'amount' => $this->settings->money($priced->couponDiscount),
        ]);
        Coupon::query()->whereKey($order->coupon_id)->increment('used_count');
    }
}

<?php

namespace App\Domain\Order\Actions;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Address\Models\UserAddress;
use App\Domain\Auth\Models\User;
use App\Domain\Cart\CartPricing;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricedLine;
use App\Domain\Catalog\Actions\RefreshProductSummary;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\VariantChannelStock;
use App\Domain\Catalog\ProductImages;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderAddress;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Support\Settings;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout: turns the customer's cart into an order. Everything happens in ONE database transaction:
 *
 *   1. lock the cart row (a second tap on "Place order" waits here, then finds an empty cart or - with the same idempotency key - the order)
 *   2. lock the stock rows of the cart's options (FOR UPDATE, always in variant order, so two checkouts can never deadlock or oversell)
 *   3. lock the coin batches, price the cart again from live data (CartPricing) and re-validate everything
 *      (stock, quantity limits, minimum order, cash-on-delivery limit, address ownership, the total the customer saw)
 *   4. write order + lines + address snapshots + payment + history, deduct the stock (movement type `sale`), debit the coins, empty the cart
 *
 * Stock is DEDUCTED when the order is placed (`stock_qty - qty`), not reserved: a COD order is a real order from the first minute.
 * `reserved_qty` stays free for online payments later (hold while the payment is pending). Cancelling puts the stock back.
 */
class PlaceOrder
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly Settings $settings,
        private readonly RewardLedger $ledger,
        private readonly SaveAddress $addresses,
        private readonly RefreshProductSummary $refreshProduct,
    ) {}

    /**
     * @param  array{address_id?:int, address?:array, save_address?:bool, billing_address_id?:int, payment_method?:string, coins_to_redeem?:int, customer_note?:string, expected_total?:numeric}  $input
     * @return array{0: Order, 1: bool}  the order and whether it is an earlier order returned for the same idempotency key
     */
    public function handle(User $user, SalesChannel $channel, array $input, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->replay($user, $idempotencyKey))) {
            return [$existing, true];
        }

        try {
            $order = DB::transaction(fn () => $this->place($user, $channel, $input, $idempotencyKey), 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey && ($existing = $this->replay($user, $idempotencyKey))) {
                return [$existing, true];   // two requests with the same key raced: the loser gets the winner's order
            }
            throw $e;
        }

        return [$order, ! $order->wasRecentlyCreated];
    }

    private function place(User $user, SalesChannel $channel, array $input, ?string $key): Order
    {
        $cart = Cart::query()->where('user_id', $user->id)->where('channel_id', $channel->id)->where('status', Cart::ACTIVE)->lockForUpdate()->first();

        if ($key && ($existing = $this->replay($user, $key))) {
            return $existing;   // the same request arrived while the first one was running
        }
        $variantIds = $cart ? CartItem::query()->where('cart_id', $cart->id)->pluck('variant_id') : collect();
        if (! $cart || $variantIds->isEmpty()) {
            throw new CommerceException('cart_empty', 'Your cart is empty.');
        }

        $stocks = VariantChannelStock::query()->where('channel_id', $channel->id)->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');
        $wallet = $this->ledger->spendable($user->id, lock: true);

        $coins = isset($input['coins_to_redeem']) ? (int) $input['coins_to_redeem'] : (int) $cart->coins_to_redeem;
        $priced = $this->pricing->forCart($cart, $channel, $user, $coins, $stocks, $wallet);

        $this->validate($priced, $input);
        [$shipping, $billing] = $this->addresses($user, $input);
        $this->validateDelivery($shipping);

        $order = $this->createOrder($user, $channel, $priced, $shipping, $billing, $input, $key);
        $this->createItems($order, $priced);
        $this->deductStock($order, $channel, $priced, $stocks);

        if ($priced->coins['applied'] > 0) {
            $this->ledger->redeem($user->id, $priced->coins['applied'], $order);
        }

        CartItem::query()->where('cart_id', $cart->id)->delete();
        $cart->forceFill(['coins_to_redeem' => 0])->save();

        foreach (collect($priced->countedLines())->map(fn (PricedLine $l) => $l->product)->unique('id') as $product) {
            $this->refreshProduct->handle($product);
        }

        return $order->load(['items', 'addresses', 'history', 'payment', 'channel']);
    }

    private function replay(User $user, string $key): ?Order
    {
        return Order::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first()?->load(['items', 'addresses', 'history', 'payment', 'channel']);
    }

    private function validate(PricedCart $priced, array $input): void
    {
        if ($priced->countedLines() === [] || $priced->hasBlockingIssue()) {
            $lines = [];
            foreach ($priced->lines as $line) {
                foreach ($line->issues as $issue) {
                    if ($issue['blocking']) {
                        $lines[] = ['variant_id' => $line->variant->id, 'code' => $issue['code'], 'message' => $issue['message']];
                    }
                }
            }
            throw new CommerceException('cart_invalid', $lines[0]['message'] ?? 'Your cart is empty.', 'cart', 422, ['lines' => $lines]);
        }

        if (! $priced->meetsMinimum()) {
            $min = $this->pricing->fmt($priced->minOrderAmount);
            $more = $this->pricing->fmt($priced->minOrderAmount - $priced->subtotal);
            throw new CommerceException('below_min_order', "The minimum order amount is $min. Add items worth $more more.", 'cart');
        }

        if (isset($input['expected_total']) && app(Settings::class)->cents($input['expected_total']) !== $priced->grandTotal) {
            throw new CommerceException('price_changed', 'The prices in your cart have changed. Please check the new total.', 'expected_total', 409, [
                'expected_total' => (float) $input['expected_total'], 'current_total' => app(Settings::class)->money($priced->grandTotal),
            ]);
        }

        // payment: only cash on delivery exists today; online methods get their own branch here later
        $method = PaymentMethod::tryFrom((string) ($input['payment_method'] ?? PaymentMethod::Cod->value));
        if ($method !== PaymentMethod::Cod) {
            throw new CommerceException('payment_method_invalid', 'Choose a valid payment method.', 'payment_method');
        }
        $codMax = $this->settings->codMaxCents();
        if ($codMax > 0 && $priced->grandTotal > $codMax) {
            throw new CommerceException('cod_not_allowed', 'Cash on delivery is available up to '.$this->pricing->fmt($codMax).' per order. Please reduce your order.', 'payment_method');
        }
        foreach ($priced->countedLines() as $line) {
            if (! ($line->variant->is_cod_available ?? $line->product->is_cod_available)) {
                throw new CommerceException('cod_not_allowed', 'Cash on delivery is not available for "'.($line->variant->name ?: $line->product->name).'".', 'payment_method');
            }
        }
    }

    /** @return array{0: array, 1: array}  shipping and billing address fields (snapshots) */
    private function addresses(User $user, array $input): array
    {
        if (! empty($input['address_id'])) {
            $shipping = $this->owned($user, (int) $input['address_id'], 'address_id');
        } elseif (! empty($input['address'])) {
            $saved = ($input['save_address'] ?? true)
                ? $this->addresses->create($user, $input['address'])->toArray()
                : $input['address'];
            $shipping = $saved;
        } else {
            throw new CommerceException('address_required', 'Choose a delivery address.', 'address_id');
        }

        $billing = ! empty($input['billing_address_id']) ? $this->owned($user, (int) $input['billing_address_id'], 'billing_address_id') : $shipping;

        return [$shipping, $billing];
    }

    /** Another customer's address is reported exactly like a missing one. */
    private function owned(User $user, int $id, string $field): array
    {
        $address = UserAddress::query()->where('user_id', $user->id)->find($id);
        if (! $address) {
            throw new CommerceException('address_not_found', 'This address was not found. Please choose another one.', $field);
        }

        return $address->toArray();
    }

    /** Pincodes can be marked unserviceable / no-COD in the `pincodes` table; a pincode that is not listed is accepted. */
    private function validateDelivery(array $shipping): void
    {
        $row = DB::table('pincodes')->where('pincode', $shipping['postcode'] ?? null)->first();
        if ($row && ! $row->is_serviceable) {
            throw new CommerceException('pincode_not_serviceable', 'Sorry, we do not deliver to this postcode yet.', 'address_id');
        }
        if ($row && ! $row->cod_available) {
            throw new CommerceException('cod_not_allowed', 'Cash on delivery is not available for this postcode.', 'payment_method');
        }
    }

    private function createOrder(User $user, SalesChannel $channel, PricedCart $priced, array $shipping, array $billing, array $input, ?string $key): Order
    {
        $order = Order::create([
            'order_number' => 'TMP-'.Str::random(20),   // replaced below, once the id exists
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'customer_name' => $user->full_name,
            'customer_email' => $user->email,
            'customer_phone' => $user->mobile ?: ($shipping['phone'] ?? null),
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::CodPending,
            'fulfillment_status' => 'unfulfilled',
            'payment_method' => PaymentMethod::Cod->value,
            'items_count' => $priced->unitsCount,
            'mrp_total' => app(Settings::class)->money($priced->itemsTotal),
            'product_discount_total' => app(Settings::class)->money($priced->productDiscount),
            'bulk_discount_total' => app(Settings::class)->money($priced->bulkDiscount),
            'items_subtotal' => app(Settings::class)->money($priced->subtotal),
            'discount_total' => 0,
            'coins_redeemed' => $priced->coins['applied'],
            'coins_discount' => app(Settings::class)->money($priced->coinsDiscount),
            'shipping_fee' => app(Settings::class)->money($priced->deliveryFee),
            'cod_fee' => 0,
            'tax_total' => app(Settings::class)->money($priced->taxIncluded),
            'grand_total' => app(Settings::class)->money($priced->grandTotal),
            'coins_to_earn' => $priced->coinsToEarn,
            'customer_gst_number' => $shipping['gst_number'] ?? null,
            'customer_note' => trim((string) ($input['customer_note'] ?? '')) ?: null,
            'estimated_delivery_date' => now()->addDays((int) $channel->delivery_days)->toDateString(),
            'placed_at' => now(),
            'source' => 'web',
            'idempotency_key' => $key,
        ]);
        $order->forceFill(['order_number' => sprintf('%s-%s-%06d', strtoupper((string) app(Settings::class)->get('order_number_prefix')), now()->format('Y'), $order->id)])->save();

        foreach (['shipping' => $shipping, 'billing' => $billing] as $type => $address) {
            OrderAddress::create(['order_id' => $order->id, 'type' => $type, 'address_type' => $address['type'] ?? null]
                + collect(UserAddress::SNAPSHOT_FIELDS)->mapWithKeys(fn ($f) => [$f => $address[$f] ?? ($f === 'country' ? 'Pakistan' : null)])->all());
        }

        OrderStatusHistory::create([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending->value, 'note' => 'Order placed',
            'changed_by_type' => 'customer', 'changed_by_id' => $user->id, 'is_customer_visible' => true,
        ]);

        Payment::create([
            'payable_type' => Payment::ORDER, 'payable_id' => $order->id, 'method' => PaymentMethod::Cod->value,
            'amount' => app(Settings::class)->money($priced->grandTotal), 'currency' => $this->settings->currencyCode(), 'status' => Payment::COD_PENDING,
        ]);

        return $order;
    }

    private function createItems(Order $order, PricedCart $priced): void
    {
        foreach ($priced->countedLines() as $line) {
            $variant = $line->variant;
            $product = $line->product;
            $own = ProductImages::gallery($product, $variant->id)[0] ?? null;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'brand_id' => $product->brand_id,
                'sku' => $variant->sku,
                'name' => $product->name,
                'option_name' => $variant->name,
                'brand_name' => $product->brand?->name,
                'image_url' => $own['medium_url'] ?? ProductImages::primary($product)['image_url'],
                'mrp' => app(Settings::class)->money($line->mrp),
                'base_price' => app(Settings::class)->money($line->basePrice),
                'unit_price' => app(Settings::class)->money($line->unitPrice),
                'bulk_min_qty' => $line->bulk['min_qty'] ?? null,
                'qty' => $line->qty,
                'discount' => app(Settings::class)->money($line->productDiscount() + $line->bulkDiscount()),
                'bulk_discount' => app(Settings::class)->money($line->bulkDiscount()),
                'tax_rate' => $product->gst_rate,
                'tax_amount' => app(Settings::class)->money($line->taxIncluded()),
                'line_total' => app(Settings::class)->money($line->lineTotal()),
                'coins_earned' => $line->coinsToEarn(),
                'expiry_date' => $variant->expiry_date,
                'hsn_code' => $product->hsn_code,
            ]);
        }
    }

    /** Takes the units off the (locked) stock rows and logs one `sale` movement per option. A low-stock alert is logged when it runs low. */
    private function deductStock(Order $order, SalesChannel $channel, PricedCart $priced, Collection $stocks): void
    {
        foreach ($priced->countedLines() as $line) {
            $stock = $stocks->get($line->variant->id);
            if ($stock->stock_qty < $line->qty) {
                throw new CommerceException('insufficient_stock', 'Only '.max(0, $stock->stock_qty).' left in stock.', 'cart');   // cannot happen after the check; the last guard against overselling
            }

            $stock->stock_qty -= $line->qty;
            $stock->save();

            InventoryMovement::create([
                'variant_id' => $line->variant->id, 'channel_id' => $channel->id, 'type' => 'sale', 'qty_change' => -$line->qty,
                'balance_after' => $stock->stock_qty, 'reference_type' => 'order', 'reference_id' => $order->id,
                'note' => "Order {$order->order_number}",
            ]);

            if ($stock->available() <= $stock->low_stock_threshold) {
                Audit::log(null, 'inventory.low_stock', $line->variant, [
                    'channel' => $channel->code, 'available' => $stock->available(), 'threshold' => $stock->low_stock_threshold, 'order' => $order->order_number,
                ]);
            }
        }
    }
}

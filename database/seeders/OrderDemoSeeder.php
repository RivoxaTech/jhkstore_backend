<?php

namespace Database\Seeders;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Address\Models\UserAddress;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use App\Domain\Cart\Actions\CartResolver;
use App\Domain\Cart\Actions\ManageCartItems;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\PlaceOrder;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\Payment;
use App\Domain\Reward\Actions\RewardLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL demo orders - not part of DatabaseSeeder. Run it AFTER ProductDemoSeeder:
 *   php artisan db:seed --class=OrderDemoSeeder
 *
 * Places real orders through the same actions the website and the admin panel use (PlaceOrder, ChangeOrderStatus), so stock, coins,
 * history and payments are exactly as in production: a few customers, both channels, every status (pending ... delivered, cancelled),
 * coins used on some orders, dates spread over the last three weeks. Does nothing when orders already exist or when there are no products.
 */
class OrderDemoSeeder extends Seeder
{
    /** [customer index, channel, days ago, last status, lines [variant offset => qty], coins to use] */
    private const PLAN = [
        [0, 'standard', 0, 'pending', [0 => 2, 3 => 1], 0],
        [1, 'standard', 0, 'pending', [1 => 1], 0],
        [2, 'instadent', 0, 'pending', [2 => 3], 0],
        [0, 'standard', 1, 'confirmed', [4 => 1, 5 => 2], 100],
        [3, 'standard', 1, 'pending', [6 => 1], 0],
        [1, 'standard', 2, 'packed', [7 => 2], 0],
        [2, 'standard', 3, 'shipped', [8 => 1, 9 => 1], 0],
        [4, 'instadent', 3, 'confirmed', [10 => 2], 0],
        [0, 'standard', 5, 'delivered', [11 => 4], 50],
        [3, 'standard', 7, 'delivered', [12 => 2, 13 => 1], 0],
        [1, 'standard', 9, 'shipped', [14 => 1], 0],
        [4, 'standard', 11, 'delivered', [15 => 3], 0],
        [2, 'standard', 4, 'cancelled', [16 => 2], 0],
        [0, 'standard', 14, 'cancelled', [17 => 1, 18 => 1], 25],
        [3, 'instadent', 18, 'delivered', [19 => 2], 0],
    ];

    private const CUSTOMERS = [
        ['Dr. Ayesha Khan', 'ayesha@example.com', '03211234567', 'Karachi', 'Sindh', '75500'],
        ['Dr. Bilal Ahmed', 'bilal@example.com', '03331234567', 'Islamabad', 'Islamabad Capital Territory', '44000'],
        ['Dr. Sana Malik', 'sana@example.com', '03451234567', 'Lahore', 'Punjab', '54000'],
        ['Dr. Hamza Raza', 'hamza@example.com', '03001239876', 'Rawalpindi', 'Punjab', '46000'],
    ];

    public function run(): void
    {
        if (Order::query()->exists()) {
            $this->command?->info('Orders already exist - nothing to do.');

            return;
        }

        $channels = SalesChannel::query()->get()->keyBy('code');
        $admin = Admin::query()->first();
        $users = $this->customers();
        $variants = $this->variants($channels['standard']->id, $channels['instadent']->id);

        if (! $admin || $users->isEmpty() || $variants->count() < 12) {
            $this->command?->warn('Run ProductDemoSeeder first (no products to order).');

            return;
        }

        $resolver = app(CartResolver::class);
        $cart = app(ManageCartItems::class);
        $place = app(PlaceOrder::class);
        $change = app(ChangeOrderStatus::class);
        $made = 0;

        foreach (self::PLAN as $n => [$who, $channelCode, $daysAgo, $final, $lines, $coins]) {
            $user = $users[$who];
            $channel = $channels[$channelCode];

            try {
                $basket = $resolver->createForUser($user, $channel);
                $cart->clear($basket);
                foreach ($lines as $offset => $qty) {
                    $cart->add($basket, $channel, $variants[($offset + $n) % $variants->count()], $qty, clamp: true);
                }
                [$order] = $place->handle($user, $channel, [
                    'address_id' => UserAddress::query()->where('user_id', $user->id)->orderByDesc('is_default')->value('id'),
                    'coins_to_redeem' => $coins,
                    'customer_note' => $n % 4 === 0 ? 'Please deliver in the morning.' : null,
                ], "demo-order-$n");

                foreach ($this->path($final) as $step) {
                    $data = match ($step) {
                        OrderStatus::Shipped => ['courier' => $n % 2 ? 'TCS' : 'Leopards', 'awb_number' => 'DEMO'.(700000 + $n * 137)],
                        OrderStatus::Cancelled => ['reason' => 'Customer asked to cancel (demo).'],
                        default => [],
                    };
                    $change->handle($order, $step, $admin, $data);
                }
                $this->backdate($order->id, $daysAgo, $n);
                $made++;
            } catch (\Throwable $e) {
                $this->command?->warn("Order #$n skipped: ".$e->getMessage());
            }
        }

        $this->command?->info("$made demo orders created.");
    }

    /** The seeded test customer plus a few demo customers, each with an address (and some coins to spend). */
    private function customers()
    {
        $users = collect([User::query()->where('email', env('SEED_CUSTOMER_EMAIL', 'test@gmail.com'))->first()])->filter();
        $ledger = app(RewardLedger::class);

        foreach (self::CUSTOMERS as [$name, $email, $mobile, $city, $state, $postcode]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['full_name' => $name, 'mobile' => $mobile, 'password' => 'test', 'professional_type' => 'dentist', 'is_active' => true],
            );
            if ($user->wasRecentlyCreated) {
                $ledger->grant($user->id, 200, 'Welcome coins (demo data)');
                app(SaveAddress::class)->create($user, [
                    'label' => 'Clinic', 'type' => 'clinic', 'name' => $name, 'phone' => $mobile, 'line1' => 'Clinic 12, Main Road',
                    'area' => 'Central Market', 'city' => $city, 'state' => $state, 'postcode' => $postcode, 'country' => 'Pakistan',
                ]);
            }
            $users->push($user);
        }

        return $users->values();
    }

    /** Ids of active variants that have plenty of stock on BOTH channels (under Rs 15,000 each, so the COD limit never blocks). */
    private function variants(int $standardId, int $instadentId)
    {
        $enough = fn (int $channelId) => DB::table('variant_channel_stock')->where('channel_id', $channelId)->where('is_active', true)
            ->whereRaw('stock_qty - reserved_qty >= 20')->select('variant_id');

        return ProductVariant::query()->where('is_active', true)
            ->whereIn('id', $enough($standardId))->whereIn('id', $enough($instadentId))
            ->where('selling_price', '<=', 15000)->orderBy('id')->limit(60)->pluck('id')->values();
    }

    /** @return list<OrderStatus> the steps from pending to the wanted status */
    private function path(string $final): array
    {
        return match ($final) {
            'confirmed' => [OrderStatus::Confirmed],
            'packed' => [OrderStatus::Confirmed, OrderStatus::Packed],
            'shipped' => [OrderStatus::Confirmed, OrderStatus::Packed, OrderStatus::Shipped],
            'delivered' => [OrderStatus::Confirmed, OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered],
            'cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            default => [],
        };
    }

    /** Moves every date of the order (and its history / shipment / payment) back in time; spreads today's orders over the day. */
    private function backdate(int $orderId, int $daysAgo, int $n): void
    {
        $minutes = $daysAgo * 1440 + ($daysAgo === 0 ? 0 : ($n * 37) % 600);
        if ($minutes === 0) {
            return;
        }
        $shift = fn (string $table, string $key, array $columns) => DB::table($table)->where($key, $orderId)->update(
            collect($columns)->mapWithKeys(fn ($c) => [$c => DB::raw("DATE_SUB($c, INTERVAL $minutes MINUTE)")])->all()
        );

        $shift('orders', 'id', ['created_at', 'updated_at', 'placed_at', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at']);
        $shift('order_status_history', 'order_id', ['created_at']);
        $shift('shipments', 'order_id', ['created_at', 'updated_at', 'shipped_at']);
        DB::table('payments')->where('payable_type', Payment::ORDER)->where('payable_id', $orderId)->update([
            'created_at' => DB::raw("DATE_SUB(created_at, INTERVAL $minutes MINUTE)"), 'updated_at' => DB::raw("DATE_SUB(updated_at, INTERVAL $minutes MINUTE)"), 'paid_at' => DB::raw("DATE_SUB(paid_at, INTERVAL $minutes MINUTE)"),
        ]);
    }
}

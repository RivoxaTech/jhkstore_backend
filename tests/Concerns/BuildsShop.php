<?php

namespace Tests\Concerns;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Address\Models\UserAddress;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Models\Order;
use App\Domain\Reward\Actions\RewardLedger;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Fixtures for the cart, checkout and order tests: customers, addresses, products with bulk prices, and cart/order calls. */
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

    protected function customer(string $email = 'buyer@example.test', string $name = 'Asha Khan'): User
    {
        return User::create(['full_name' => $name, 'email' => $email, 'mobile' => '03'.random_int(100000000, 999999999), 'password' => 'secret-pass', 'is_active' => true]);
    }

    /** Makes `$user` the caller of the following requests (null = a guest). */
    protected function actAs(?User $user): static
    {
        $this->app['auth']->forgetGuards();
        if ($user) {
            Sanctum::actingAs($user, ['*']);
        }

        return $this;
    }

    protected function address(User $user, array $overrides = []): UserAddress
    {
        return app(SaveAddress::class)->create($user, $overrides + [
            'type' => 'work', 'name' => $user->full_name, 'phone' => '03001234567', 'alt_phone' => '03111234567',
            'line1' => 'Shop 4, Market Plaza', 'area' => 'Block B, Gulberg', 'landmark' => 'Near the park',
            'city' => 'Lahore', 'state' => 'Punjab', 'postcode' => '54660', 'country' => 'Pakistan',
        ]);
    }

    /**
     * A product with ONE option: MRP 200 / price 160 / 5 coins, bulk prices "5+ for 152" and "10+ for 140", 50 units in stock.
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
                'name' => "Shop Item $n (15ml)", 'sku' => "SHOP-$n-V", 'is_default' => true, 'mrp' => 200, 'selling_price' => 160, 'reward_points' => 5,
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

    protected function giveCoins(User $user, int $coins): void
    {
        app(RewardLedger::class)->grant($user->id, $coins, 'test coins');
    }

    protected function setSetting(string $key, mixed $value): void
    {
        app(\App\Domain\Support\Settings::class)->set([$key => $value]);
    }

    /** Logs in as an admin (the customer in front of the request is forgotten). */
    protected function asAdmin(): Admin
    {
        $this->app['auth']->forgetGuards();
        $admin = Admin::query()->where('username', 'tester')->first() ?? Admin::create(['username' => 'tester', 'name' => 'Tester', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** Places a COD order for `$user` with a new product; returns the stored order. */
    protected function orderFor(User $user, int $qty = 1, array $variant = [], int $coins = 0): Order
    {
        $address = UserAddress::query()->where('user_id', $user->id)->first() ?? $this->address($user);
        $variantId = $this->variantOf($this->shopProduct($variant));
        $this->actAs($user);
        $this->addToCart($variantId, $qty)->assertCreated();
        $number = $this->placeOrder(['address_id' => $address->id, 'coins_to_redeem' => $coins])->assertCreated()->json('data.order_number');

        return Order::query()->where('order_number', $number)->firstOrFail();
    }

    // ------------------------------------------------------------------ calls

    protected function addToCart(int $variantId, int $qty = 1, array $headers = []): TestResponse
    {
        return $this->flushHeaders()->withHeaders($headers)->postJson(self::STORE."/cart/items", ['variant_id' => $variantId, 'qty' => $qty]);
    }

    protected function cart(array $headers = [], string $query = ''): TestResponse
    {
        return $this->flushHeaders()->withHeaders($headers)->getJson(self::STORE."/cart".$query);
    }

    protected function placeOrder(array $body = [], array $headers = []): TestResponse
    {
        return $this->flushHeaders()->withHeaders($headers)->postJson(self::STORE."/orders", $body);
    }
}

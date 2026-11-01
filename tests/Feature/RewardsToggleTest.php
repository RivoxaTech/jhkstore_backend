<?php

namespace Tests\Feature;

use App\Domain\Reward\Actions\RewardLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Reward points are optional: switched off, nothing about them is earned, spent, stored or shown. Switched on, nothing changes. */
class RewardsToggleTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedShop();
    }

    private function noPoints(array $data): void
    {
        $json = json_encode($data);
        $this->assertStringNotContainsString('coins', $json);
        $this->assertStringNotContainsString('reward_points', $json);
    }

    public function test_with_the_feature_off_nothing_is_shown_in_the_storefront(): void
    {
        $this->setSetting('rewards_enabled', false);
        $product = $this->shopProduct();
        $variant = $this->variantOf($product);

        $this->noPoints($this->getJson(self::STORE.'/products')->json());
        $this->noPoints($this->getJson(self::STORE.'/products/'.$product->slug)->json());
        $this->noPoints($this->addToCart($variant, 2)->json());
        $this->getJson(self::STORE.'/settings')->assertJsonPath('features.rewards', false)->assertJsonPath('rewards', null);
    }

    public function test_with_the_feature_off_nothing_is_earned_or_redeemed_at_checkout(): void
    {
        $this->setSetting('rewards_enabled', false);
        $user = $this->customer();
        $this->giveCoins($user, 500);   // the wallet may hold points from before: they are simply not usable
        $address = $this->address($user);
        $this->actAs($user);
        $this->addToCart($this->variantOf($this->shopProduct()), 2)->assertCreated();

        $this->putJson(self::STORE.'/cart/coins', ['coins_to_redeem' => 100])->assertNotFound();

        $res = $this->placeOrder(['address_id' => $address->id, 'coins_to_redeem' => 100])->assertCreated();
        $this->noPoints($res->json());
        $this->assertDatabaseHas('orders', ['id' => $res->json('data.id'), 'coins_redeemed' => 0, 'coins_to_earn' => 0, 'coins_discount' => 0]);
        $this->assertSame(0, app(RewardLedger::class)->spendable($user->id));
        $this->assertSame(500, (int) DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));   // untouched

        // delivering the order credits nothing
        $this->asAdmin();
        foreach (['confirmed', 'packed'] as $s) {
            $this->postJson(self::ADMIN."/orders/{$res->json('data.id')}/status", ['status' => $s])->assertOk();
        }
        $this->postJson(self::ADMIN."/orders/{$res->json('data.id')}/status", ['status' => 'shipped', 'courier' => 'X', 'awb_number' => '1'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$res->json('data.id')}/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(0, DB::table('reward_transactions')->where('type', 'earn')->count());
        $this->noPoints($this->getJson(self::ADMIN."/orders/{$res->json('data.id')}")->json());
    }

    public function test_with_the_feature_off_the_admin_product_has_no_points_and_saving_keeps_them(): void
    {
        $product = $this->shopProduct(['reward_points' => 7]);
        $this->asAdmin();
        $this->getJson(self::ADMIN.'/products/options')->assertJsonPath('features.rewards', true);

        $this->setSetting('rewards_enabled', false);
        $this->getJson(self::ADMIN.'/products/options')->assertJsonPath('features.rewards', false);
        $this->noPoints($this->getJson(self::ADMIN."/products/{$product->id}")->json());

        $variant = $product->variants()->first();
        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [
            ['id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'mrp' => 200, 'selling_price' => 150, 'reward_points' => 99],
        ]])->assertOk();
        $this->assertSame(7, (int) $variant->fresh()->reward_points);   // not editable while off, not lost either

        $this->setSetting('rewards_enabled', true);
        $this->getJson(self::ADMIN."/products/{$product->id}")->assertJsonPath('data.variants.0.reward_points', 7);
    }

    public function test_with_the_feature_on_the_earn_rate_covers_products_without_their_own_points(): void
    {
        $this->setSetting('reward_earn_rate', 0.1);   // 1 point per 10 currency units
        $own = $this->variantOf($this->shopProduct(['reward_points' => 5]));
        $none = $this->variantOf($this->shopProduct(['reward_points' => 0]));
        $this->actAs($this->customer());

        $this->addToCart($own, 2);
        $res = $this->addToCart($none, 5);   // 5 x 152 (bulk) = 760 -> 76 points
        $this->assertSame(10, $res->json('data.items.0.coins_to_earn'));
        $this->assertSame(76, $res->json('data.items.1.coins_to_earn'));
        $this->assertSame(86, $res->json('data.summary.coins_to_earn'));
    }

    public function test_tax_can_be_added_on_top_of_the_prices(): void
    {
        $this->actAs($this->customer());
        $variant = $this->variantOf($this->shopProduct(['mrp' => 200, 'selling_price' => 100, 'price_tiers' => []], ['tax_rate' => 10]));
        $this->setSetting('delivery_fee', 0);

        $inclusive = $this->addToCart($variant, 1)->json('data.summary');
        $this->assertEquals(100, $inclusive['grand_total']);
        $this->assertEquals(9.09, $inclusive['tax_total']);
        $this->assertTrue($inclusive['tax_inclusive']);

        $this->setSetting('tax_inclusive', false);
        $exclusive = $this->cart()->json('data.summary');
        $this->assertEquals(110, $exclusive['grand_total']);   // 100 + 10% on top
        $this->assertEquals(10, $exclusive['tax_total']);
        $this->assertFalse($exclusive['tax_inclusive']);

        // the product without its own rate follows the store default
        $other = $this->variantOf($this->shopProduct(['mrp' => 200, 'selling_price' => 100, 'price_tiers' => []]));
        $this->setSetting('tax_rate', 5);
        $this->addToCart($other, 1);
        $this->assertEquals(215, $this->cart()->json('data.summary.grand_total'));   // (100 + 10) + (100 + 5)
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Reward\Models\RewardTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CommerceSettingsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'delivery_fee' => 250, 'free_delivery_min_cart' => 5000, 'coin_value' => 2, 'cod_max_amount' => 100000, 'min_order_amount' => 500,
            'max_redeem_coins_per_order' => 1000, 'redeem_cap_order_percent' => 20, 'redeem_cap_balance_percent' => 40,
        ];
    }

    public function test_the_defaults_are_the_ones_from_the_brief(): void
    {
        $this->asAdmin();

        $this->getJson(self::ADMIN.'/settings/commerce')->assertOk()
            ->assertJsonPath('delivery_fee', 300)
            ->assertJsonPath('free_delivery_min_cart', 8500)
            ->assertJsonPath('coin_value', 1)             // 1 coin = Rs 1
            ->assertJsonPath('cod_max_amount', 150000)
            ->assertJsonPath('min_order_amount', 0)       // no minimum
            ->assertJsonPath('max_redeem_coins_per_order', 5000)
            ->assertJsonPath('currency', 'PKR')
            ->assertJsonPath('currency_symbol', 'Rs')
            ->assertJsonFragment(['code' => 'instadent', 'delivery_days' => 3]);
    }

    public function test_the_admin_changes_the_rules_and_the_shop_follows(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['channels' => [['code' => 'standard', 'delivery_days' => 7]]]))
            ->assertOk()->assertJsonPath('delivery_fee', 250)->assertJsonPath('coin_value', 2)->assertJsonFragment(['code' => 'standard', 'delivery_days' => 7]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.commerce_updated', 'actor_type' => 'admin']);

        // the website reads the same values
        $this->actAs(null);
        $this->getJson(self::STORE.'/settings/delivery')->assertOk()
            ->assertJsonPath('delivery_fee', 250)->assertJsonPath('free_delivery_min_cart', 5000)->assertJsonPath('coin_value', 2)
            ->assertJsonPath('min_order_amount', 500)->assertJsonPath('cod_max_amount', 100000);

        // and so does the cart
        $variant = $this->variantOf($this->shopProduct());
        $s = $this->addToCart($variant, 5)->json('data.summary');   // subtotal 760
        $this->assertEquals(250, $s['delivery_fee']);
        $this->assertEquals(2, $s['coins']['coin_value']);
        $this->assertEquals(500, $s['min_order_amount']);
    }

    public function test_the_values_are_validated_with_messages_the_form_can_show(): void
    {
        $this->asAdmin();

        $res = $this->putJson(self::ADMIN.'/settings/commerce', [])->assertStatus(422);
        $res->assertJsonValidationErrors(['delivery_fee', 'free_delivery_min_cart', 'coin_value', 'cod_max_amount', 'min_order_amount', 'max_redeem_coins_per_order', 'redeem_cap_order_percent', 'redeem_cap_balance_percent']);
        $this->assertSame('Enter how much one coin is worth.', $res->json('errors.coin_value.0'));

        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['coin_value' => 0]))->assertStatus(422)->assertJsonPath('errors.coin_value.0', 'One coin must be worth at least 0.01.');
        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['delivery_fee' => -1]))->assertStatus(422)->assertJsonValidationErrors('delivery_fee');
        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['redeem_cap_order_percent' => 101]))->assertStatus(422)->assertJsonValidationErrors('redeem_cap_order_percent');
        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['channels' => [['code' => 'nope', 'delivery_days' => 3]]]))->assertStatus(422)->assertJsonValidationErrors('channels.0.code');
    }

    public function test_zero_means_unlimited_or_off(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings/commerce', $this->valid(['free_delivery_min_cart' => 0, 'cod_max_amount' => 0, 'min_order_amount' => 0, 'delivery_fee' => 0]))->assertOk();

        $user = $this->customer();
        $order = $this->orderFor($user, 5);   // would be refused by any COD limit; delivery is free because the fee is 0

        $this->assertEquals(0, $order->shipping_fee);
        $this->assertEquals(760, $order->grand_total);
    }

    public function test_expired_coins_leave_the_wallet(): void
    {
        $user = $this->customer();
        $this->giveCoins($user, 100);
        $this->giveCoins($user, 40);
        RewardTransaction::where('user_id', $user->id)->orderBy('id')->first()->update(['expires_at' => now()->subDay()]);

        $this->artisan('rewards:expire')->assertSuccessful();

        $this->assertSame('expired', RewardTransaction::where('user_id', $user->id)->orderBy('id')->first()->status);
        $this->assertSame(40, DB::table('reward_wallets')->where('user_id', $user->id)->value('available'));
        $this->assertSame(40, app(RewardLedger::class)->spendable($user->id));
    }
}

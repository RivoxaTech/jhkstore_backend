<?php

namespace Tests\Feature;

use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Reward\Models\RewardTransaction;
use App\Domain\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    public function test_a_fresh_install_starts_with_neutral_defaults(): void
    {
        DB::table('settings')->delete();
        app(Settings::class)->flush();
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $s = app(Settings::class);

        $this->assertSame('My Store', $s->get('store_name'));
        $this->assertSame('USD', $s->currencyCode());
        $this->assertSame('$', $s->currencySymbol());
        $this->assertSame(2, $s->decimals());
        $this->assertEquals(0, $s->get('tax_rate'));
        $this->assertTrue($s->get('tax_inclusive'));
        $this->assertSame('ORD', $s->get('order_number_prefix'));
        $this->assertTrue($s->brandsEnabled());
        $this->assertFalse($s->rewardsEnabled());          // reward points are off until the client switches them on
    }

    public function test_seeding_again_keeps_the_values_an_admin_changed(): void
    {
        $this->setSetting('store_name', 'Acme Shop');
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $this->assertSame('Acme Shop', app(Settings::class)->get('store_name'));
    }

    public function test_the_admin_gets_every_setting_grouped_and_can_change_some(): void
    {
        $this->asAdmin();

        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['general', 'currency', 'tax', 'orders', 'shipping', 'features', 'rewards'], array_column($res->json('groups'), 'key'));
        $res->assertJsonPath('values.delivery_fee', 300)->assertJsonPath('values.currency_symbol', 'Rs');

        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Acme Shop', 'delivery_fee' => 250, 'tax_rate' => 12.5, 'tax_inclusive' => false])
            ->assertOk()->assertJsonPath('values.store_name', 'Acme Shop')->assertJsonPath('values.delivery_fee', 250);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'actor_type' => 'admin']);

        // the storefront reads the same values
        $this->actAs(null);
        $this->getJson(self::STORE.'/settings')->assertOk()
            ->assertJsonPath('store.name', 'Acme Shop')->assertJsonPath('delivery.fee', 250)
            ->assertJsonPath('tax.rate', 12.5)->assertJsonPath('tax.inclusive', false)
            ->assertJsonPath('currency.symbol', 'Rs')->assertJsonPath('features.brands', true);

        // and so does the cart
        $variant = $this->variantOf($this->shopProduct());
        $this->assertEquals(250, $this->addToCart($variant, 5)->json('data.summary.delivery_fee'));
    }

    public function test_values_are_validated(): void
    {
        $this->asAdmin();

        $this->putJson(self::ADMIN.'/settings', ['delivery_fee' => -1])->assertStatus(422)->assertJsonValidationErrors('delivery_fee');
        $this->putJson(self::ADMIN.'/settings', ['redeem_cap_order_percent' => 101])->assertStatus(422)->assertJsonValidationErrors('redeem_cap_order_percent');
        $this->putJson(self::ADMIN.'/settings', ['reward_redeem_rate' => 0])->assertStatus(422)->assertJsonValidationErrors('reward_redeem_rate');
        $this->putJson(self::ADMIN.'/settings', ['currency_decimals' => 3])->assertStatus(422)->assertJsonValidationErrors('currency_decimals');
        $this->putJson(self::ADMIN.'/settings', ['order_number_prefix' => 'A B!'])->assertStatus(422)->assertJsonValidationErrors('order_number_prefix');
        $this->putJson(self::ADMIN.'/settings', ['store_name' => ''])->assertStatus(422)->assertJsonValidationErrors('store_name');
        $this->putJson(self::ADMIN.'/settings', ['no_such_setting' => 1])->assertStatus(422)->assertJsonValidationErrors('no_such_setting');
        $this->putJson(self::ADMIN.'/settings', ['store_logo' => 'x'])->assertStatus(422)->assertJsonValidationErrors('store_logo');   // only via the upload
    }

    public function test_settings_need_an_admin(): void
    {
        $this->actAs(null);
        $this->getJson(self::ADMIN.'/settings')->assertUnauthorized();
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'X'])->assertUnauthorized();
    }

    public function test_the_logo_is_uploaded_shown_and_removed(): void
    {
        $this->asAdmin();

        $res = $this->post(self::ADMIN.'/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith('http', $res->json('logo_url'));
        Storage::disk('public')->assertExists(app(Settings::class)->get('store_logo'));

        $this->getJson(self::STORE.'/settings')->assertJsonPath('store.logo_url', $res->json('logo_url'));

        $path = app(Settings::class)->get('store_logo');
        $this->deleteJson(self::ADMIN.'/settings/logo')->assertOk()->assertJsonPath('logo_url', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_zero_means_unlimited_or_off(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings', ['free_delivery_min_cart' => 0, 'cod_max_amount' => 0, 'min_order_amount' => 0, 'delivery_fee' => 0])->assertOk();

        $user = $this->customer();
        $order = $this->orderFor($user, 5);   // would be refused by any COD limit; delivery is free because the fee is 0

        $this->assertEquals(0, $order->shipping_fee);
        $this->assertEquals(760, $order->grand_total);
    }

    public function test_the_order_number_uses_the_configured_prefix(): void
    {
        $this->setSetting('order_number_prefix', 'JHK');

        $order = $this->orderFor($this->customer(), 1);

        $this->assertMatchesRegularExpression('/^JHK-\d{4}-\d{6}$/', $order->order_number);
    }

    // ------------------------------------------------------------------ brands switch

    public function test_brands_can_be_switched_off(): void
    {
        $category = $this->makeCategory('Cat');
        $brand = $this->makeBrand('Acme Brand');
        $product = $this->makeProduct($category, ['brand_id' => $brand->id, 'name' => 'Branded']);

        $this->getJson(self::STORE.'/brands')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::STORE.'/products')->assertJsonPath('data.0.brand.name', 'Acme Brand');

        $this->setSetting('brands_enabled', false);

        // storefront: no brand pages, no brand in product responses, no brand filter, no brand-name search
        $this->getJson(self::STORE.'/brands')->assertNotFound();
        $this->getJson(self::STORE.'/brands/'.$brand->slug)->assertNotFound();
        $this->getJson(self::STORE.'/products')->assertJsonPath('data.0.brand', null);
        $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->assertJsonPath('data.brand', null);
        $this->assertCount(1, $this->getJson(self::STORE.'/products?brand=nothing')->json('data'));   // the filter is ignored
        $this->assertCount(0, $this->getJson(self::STORE.'/products?q=acme')->json('data'));          // the brand name is not searched
        $this->getJson(self::STORE.'/settings')->assertJsonPath('features.brands', false);

        // admin: brand endpoints are hidden, the product form has no brands, a product cannot change its brand
        $this->asAdmin();
        $this->getJson(self::ADMIN.'/brands')->assertNotFound();
        $this->postJson(self::ADMIN.'/brands', ['name' => 'New'])->assertNotFound();
        $this->getJson(self::ADMIN.'/products/options')->assertJsonPath('brands', [])->assertJsonPath('features.brands', false);
        $this->getJson(self::ADMIN.'/products/'.$product->id)->assertJsonPath('data.brand', null);
        $this->putJson(self::ADMIN.'/products/'.$product->id, ['brand_id' => null, 'name' => 'Renamed'])->assertOk();
        $this->assertSame($brand->id, $product->fresh()->brand_id);   // untouched while brands are off
        $this->getJson(self::ADMIN.'/dashboard')->assertJsonPath('brands', null);

        // an inactive brand no longer hides its products while brands are off
        $brand->update(['is_active' => false]);
        $this->actAs(null);
        $this->assertCount(1, $this->getJson(self::STORE.'/products')->json('data'));
        $this->setSetting('brands_enabled', true);
        $this->assertCount(0, $this->getJson(self::STORE.'/products')->json('data'));
    }

    // ------------------------------------------------------------------ rewards

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

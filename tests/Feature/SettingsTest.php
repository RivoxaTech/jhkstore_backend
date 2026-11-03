<?php

namespace Tests\Feature;

use App\Domain\Support\Models\Setting;
use App\Domain\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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
        $this->assertSame(['general', 'branding', 'banners', 'footer', 'currency_tax', 'orders_delivery', 'seo'], array_column($res->json('tabs'), 'key'));
        $this->assertSame(['General', 'Branding', 'Banners', 'Footer & Social', 'Currency & Tax', 'Orders & Delivery', 'SEO'], array_column($res->json('tabs'), 'label'));
        $this->assertSame(['currency', 'tax'], array_column($res->json('tabs.4.sections'), 'key'));
        $res->assertJsonPath('values.delivery_fee', 300)->assertJsonPath('values.currency_symbol', '$');
        $this->assertSame([], $res->json('tabs.2.sections'));   // Banners: managed through the banner endpoints

        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Acme Shop', 'delivery_fee' => 250, 'tax_rate' => 12.5, 'tax_inclusive' => false])
            ->assertOk()->assertJsonPath('values.store_name', 'Acme Shop')->assertJsonPath('values.delivery_fee', 250);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'actor_type' => 'admin']);

        // the storefront reads the same values
        $this->app['auth']->forgetGuards();
        $this->getJson(self::STORE.'/settings')->assertOk()
            ->assertJsonPath('store.name', 'Acme Shop')->assertJsonPath('delivery.fee', 250)
            ->assertJsonPath('tax.rate', 12.5)->assertJsonPath('tax.inclusive', false)
            ->assertJsonPath('currency.symbol', '$')->assertJsonMissingPath('features')->assertJsonMissingPath('rewards');

        // and so does the quote
        $variant = $this->variantOf($this->shopProduct());
        $this->assertEquals(250, $this->quote([$variant => 5])->json('data.summary.delivery_fee'));
    }

    public function test_values_are_validated(): void
    {
        $this->asAdmin();

        $this->putJson(self::ADMIN.'/settings', ['delivery_fee' => -1])->assertStatus(422)->assertJsonValidationErrors('delivery_fee');
        $this->putJson(self::ADMIN.'/settings', ['delivery_days' => 99])->assertStatus(422)->assertJsonPath('errors.delivery_days.0', 'The delivery time (days) field must not be greater than 60.');
        $this->putJson(self::ADMIN.'/settings', ['tax_rate' => 101])->assertStatus(422)->assertJsonValidationErrors('tax_rate');
        $this->putJson(self::ADMIN.'/settings', ['rewards_enabled' => true])->assertStatus(422)->assertJsonValidationErrors('rewards_enabled');   // the feature is gone
        $this->putJson(self::ADMIN.'/settings', ['return_window_days' => 5])->assertStatus(422)->assertJsonValidationErrors('return_window_days');
        $this->putJson(self::ADMIN.'/settings', ['currency_decimals' => 3])->assertStatus(422)->assertJsonValidationErrors('currency_decimals');
        $this->putJson(self::ADMIN.'/settings', ['order_number_prefix' => 'A B!'])->assertStatus(422)->assertJsonValidationErrors('order_number_prefix');
        foreach (['92', '+0', '+92 3', '0300', '+12345', 'abc'] as $bad) {
            $this->putJson(self::ADMIN.'/settings', ['default_phone_country_code' => $bad])->assertStatus(422)->assertJsonValidationErrors('default_phone_country_code');
        }
        $this->putJson(self::ADMIN.'/settings', ['default_phone_country_code' => '92'])->assertJsonPath('errors.default_phone_country_code.0', 'Enter the country code with a + and 1 to 4 digits, for example +92.');
        $this->putJson(self::ADMIN.'/settings', ['default_phone_country_code' => '+92'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['default_phone_country_code' => ''])->assertOk();   // empty = off
        $this->putJson(self::ADMIN.'/settings', ['store_name' => ''])->assertStatus(422)->assertJsonValidationErrors('store_name');
        $this->putJson(self::ADMIN.'/settings', ['no_such_setting' => 1])->assertStatus(422)->assertJsonValidationErrors('no_such_setting');
        $this->putJson(self::ADMIN.'/settings', ['store_logo' => 'x'])->assertStatus(422)->assertJsonValidationErrors('store_logo');   // only via the upload
    }

    public function test_settings_need_an_admin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN.'/settings')->assertUnauthorized();
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'X'])->assertUnauthorized();
    }

    public function test_the_logo_is_uploaded_shown_and_removed(): void
    {
        $this->asAdmin();

        $res = $this->upload('logo', 'logo', UploadedFile::fake()->image('logo.png', 200, 80))->assertOk();
        $url = $res->json('files.store_logo');
        $this->assertStringStartsWith('http', $url);
        $media = Media::query()->where('model_type', Setting::class)->sole();
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        $this->assertSame((string) $media->id, (string) app(Settings::class)->get('store_logo'));

        $this->getJson(self::STORE.'/settings')->assertJsonPath('store.logo_url', $url);

        // a second upload replaces the first (one file per setting)
        $second = $this->upload('logo', 'logo', UploadedFile::fake()->image('logo2.png', 200, 80))->assertOk()->json('files.store_logo');
        $this->assertNotSame($url, $second);
        $this->assertSame(1, Media::query()->where('model_type', Setting::class)->count());

        $path = Media::query()->where('model_type', Setting::class)->sole()->getPathRelativeToRoot();
        $this->deleteJson(self::ADMIN.'/settings/logo')->assertOk()->assertJsonPath('files.store_logo', null);
        Storage::disk('public')->assertMissing($path);
        $this->getJson(self::STORE.'/settings')->assertJsonPath('store.logo_url', null);
    }

    public function test_pictures_are_validated_by_type_and_size(): void
    {
        $this->asAdmin();

        $this->upload('logo', 'logo', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->upload('logo', 'logo', UploadedFile::fake()->image('big.png', 100, 100)->size(3000))->assertStatus(422)->assertJsonValidationErrors('logo');   // > 2 MB
        $this->upload('logo', 'logo', UploadedFile::fake()->image('huge.png', 5000, 100))->assertStatus(422)->assertJsonValidationErrors('logo');             // > 4000 px wide
        $this->upload('logo', 'logo', UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))->assertStatus(422)->assertJsonValidationErrors('logo');   // svg can carry scripts
        $this->post(self::ADMIN.'/settings/logo', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('logo');                // nothing sent

        $this->upload('favicon', 'favicon', UploadedFile::fake()->image('f.png', 64, 64)->size(600))->assertStatus(422)->assertJsonValidationErrors('favicon');   // > 512 KB
        $this->upload('favicon', 'favicon', UploadedFile::fake()->create('f.exe', 10, 'application/octet-stream'))->assertStatus(422)->assertJsonValidationErrors('favicon');

        $this->upload('share-image', 'share_image', UploadedFile::fake()->image('s.png', 600, 300)->size(5000))->assertStatus(422)->assertJsonValidationErrors('share_image');   // > 4 MB
        $this->upload('share-image', 'share_image', UploadedFile::fake()->create('s.txt', 1, 'text/plain'))->assertStatus(422)->assertJsonValidationErrors('share_image');

        $this->assertSame(0, Media::query()->where('model_type', Setting::class)->count());
        $this->post(self::ADMIN.'/settings/nothing', [], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_favicon_and_share_image_are_uploaded_and_appear_in_the_public_response(): void
    {
        $this->asAdmin();

        $fav = $this->upload('favicon', 'favicon', UploadedFile::fake()->create('favicon.ico', 4, 'image/x-icon'))->assertOk()->json('files.store_favicon');
        $share = $this->upload('share-image', 'share_image', UploadedFile::fake()->image('share.jpg', 1200, 630))->assertOk()->json('files.seo_share_image');
        $this->assertStringStartsWith('http', $fav);
        $this->assertStringStartsWith('http', $share);
        $this->assertSame(2, Media::query()->where('model_type', Setting::class)->count());

        $this->app['auth']->forgetGuards();
        $this->getJson(self::STORE.'/settings')->assertOk()
            ->assertJsonPath('store.favicon_url', $fav)->assertJsonPath('seo.share_image_url', $share)->assertJsonPath('store.logo_url', null);

        $this->asAdmin();
        $this->deleteJson(self::ADMIN.'/settings/favicon')->assertOk()->assertJsonPath('files.store_favicon', null)->assertJsonPath('files.seo_share_image', $share);
    }

    // ------------------------------------------------------------------ footer, social links, SEO

    private function link(string $platform, string $url, string $label = '', bool $active = true): array
    {
        return ['platform' => $platform, 'label' => $label, 'url' => $url, 'active' => $active];
    }

    public function test_social_links_keep_their_order_and_any_number_of_entries(): void
    {
        $this->asAdmin();

        $links = [
            $this->link('instagram', 'https://instagram.com/acme', 'Our Instagram'),
            $this->link('facebook', 'https://facebook.com/acme'),
            $this->link('whatsapp', 'https://wa.me/491701234567', 'Chat'),
            $this->link('custom', 'https://blog.example.com', 'Blog'),
        ];
        foreach (range(1, 30) as $i) {
            $links[] = $this->link('website', "https://shop$i.example.com", "Shop $i");   // no fixed limit
        }

        $res = $this->putJson(self::ADMIN.'/settings', ['social_links' => $links])->assertOk();
        $this->assertSame(array_column($links, 'url'), array_column($res->json('values.social_links'), 'url'));

        // reorder: the new order is what is stored and what the storefront gets
        $reordered = array_reverse($links);
        $this->putJson(self::ADMIN.'/settings', ['social_links' => $reordered])->assertOk();
        $this->assertSame(array_column($reordered, 'url'), array_column(app(Settings::class)->get('social_links'), 'url'));
        $this->assertSame(array_column($reordered, 'url'), array_column($this->getJson(self::STORE.'/settings')->json('social_links'), 'url'));

        // removing entries (an empty list is valid)
        $this->putJson(self::ADMIN.'/settings', ['social_links' => []])->assertOk()->assertJsonPath('values.social_links', []);
        $this->getJson(self::STORE.'/settings')->assertJsonPath('social_links', []);
    }

    public function test_inactive_links_are_hidden_from_the_storefront_and_labels_fall_back_to_the_platform(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings', ['social_links' => [
            $this->link('youtube', 'https://youtube.com/@acme'),
            $this->link('tiktok', 'https://tiktok.com/@acme', 'TikTok', false),
            $this->link('x', 'https://x.com/acme'),
            $this->link('pinterest', 'https://pinterest.com/acme', 'Pins'),
        ]])->assertOk()->assertJsonPath('values.social_links.1.active', false);

        $this->app['auth']->forgetGuards();
        $res = $this->getJson(self::STORE.'/settings')->assertOk();
        $this->assertSame([
            ['platform' => 'youtube', 'label' => 'Youtube', 'url' => 'https://youtube.com/@acme'],
            ['platform' => 'x', 'label' => 'X', 'url' => 'https://x.com/acme'],
            ['platform' => 'pinterest', 'label' => 'Pins', 'url' => 'https://pinterest.com/acme'],
        ], $res->json('social_links'));

        // the admin still sees the inactive one
        $this->asAdmin();
        $this->assertCount(4, $this->getJson(self::ADMIN.'/settings')->json('values.social_links'));
    }

    public function test_links_are_validated(): void
    {
        $this->asAdmin();
        $put = fn (array $links) => $this->putJson(self::ADMIN.'/settings', ['social_links' => $links]);

        $res = $put([$this->link('facebook', 'not a url')])->assertStatus(422)->assertJsonValidationErrors('social_links.0.url');
        $this->assertSame('Enter a full web address starting with http:// or https://.', $res->json('errors')['social_links.0.url'][0]);   // a human message
        $put([$this->link('facebook', 'javascript:alert(1)')])->assertStatus(422)->assertJsonValidationErrors('social_links.0.url');
        $put([$this->link('facebook', 'ftp://example.com/file')])->assertStatus(422)->assertJsonValidationErrors('social_links.0.url');
        $put([$this->link('facebook', '')])->assertStatus(422)->assertJsonValidationErrors('social_links.0.url');
        $put([$this->link('myspace', 'https://example.com')])->assertStatus(422)->assertJsonValidationErrors('social_links.0.platform');
        $put([$this->link('custom', 'https://example.com', str_repeat('x', 61))])->assertStatus(422)->assertJsonValidationErrors('social_links.0.label');
        $put([$this->link('custom', 'https://example.com/'.str_repeat('a', 500))])->assertStatus(422)->assertJsonValidationErrors('social_links.0.url');
        $this->putJson(self::ADMIN.'/settings', ['social_links' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('social_links');
        $this->putJson(self::ADMIN.'/settings', ['social_links' => [['platform' => 'custom', 'url' => 'https://ok.example.com', 'active' => 'maybe']]])
            ->assertStatus(422)->assertJsonValidationErrors('social_links.0.active');

        // the second row is the bad one: nothing is saved
        $put([$this->link('facebook', 'https://facebook.com/acme'), $this->link('custom', 'oops')])->assertStatus(422)->assertJsonValidationErrors('social_links.1.url');
        $this->assertSame([], app(Settings::class)->get('social_links'));
    }

    public function test_footer_and_seo_settings_are_saved_and_public(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings', [
            'footer_text' => 'Quality goods, fast delivery.', 'footer_copyright' => '© 2026 Acme', 'support_hours' => 'Mon-Fri 9-18',
            'whatsapp_number' => '+49 170 1234567', 'seo_meta_title' => 'Acme Store', 'seo_meta_description' => 'Everything you need.',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::STORE.'/settings')->assertOk()
            ->assertJsonPath('footer', ['text' => 'Quality goods, fast delivery.', 'copyright' => '© 2026 Acme', 'support_hours' => 'Mon-Fri 9-18',
                'whatsapp_number' => '+49 170 1234567', 'whatsapp_url' => 'https://wa.me/491701234567'])
            ->assertJsonPath('seo', ['meta_title' => 'Acme Store', 'meta_description' => 'Everything you need.', 'share_image_url' => null]);

        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings', ['whatsapp_number' => 'call me'])->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');
        $this->putJson(self::ADMIN.'/settings', ['seo_meta_title' => str_repeat('t', 71)])->assertStatus(422)->assertJsonValidationErrors('seo_meta_title');
        $this->putJson(self::ADMIN.'/settings', ['seo_meta_description' => str_repeat('d', 301)])->assertStatus(422)->assertJsonValidationErrors('seo_meta_description');
    }

    public function test_the_public_response_has_neutral_empty_values_on_a_fresh_install(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::STORE.'/settings')->assertOk()
            ->assertJsonPath('social_links', [])
            ->assertJsonPath('store.favicon_url', null)
            ->assertJsonPath('footer.whatsapp_url', null)
            ->assertJsonPath('seo.share_image_url', null)
            ->assertJsonStructure(['store' => ['logo_url', 'favicon_url'], 'social_links', 'footer' => ['text', 'copyright', 'support_hours', 'whatsapp_number', 'whatsapp_url'], 'seo' => ['meta_title', 'meta_description', 'share_image_url']]);
    }

    public function test_the_settings_service_casts_links_by_type(): void
    {
        $s = app(Settings::class);
        $s->set(['social_links' => [
            ['platform' => 'nonsense', 'url' => ' https://a.example.com '],
            ['platform' => 'facebook', 'url' => ''],            // no url: dropped
            'garbage',
            ['platform' => 'x', 'label' => ' X ', 'url' => 'https://x.com/a', 'active' => 'false'],
        ]]);

        $this->assertSame([
            ['platform' => 'custom', 'label' => '', 'url' => 'https://a.example.com', 'active' => true],
            ['platform' => 'x', 'label' => 'X', 'url' => 'https://x.com/a', 'active' => false],
        ], $s->get('social_links'));
        $this->assertSame([], Settings::DEFINITIONS['social_links'][2]);
    }

    private function upload(string $slot, string $field, UploadedFile $file)
    {
        return $this->post(self::ADMIN.'/settings/'.$slot, [$field => $file], ['Accept' => 'application/json']);
    }

    public function test_zero_means_unlimited_or_off(): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN.'/settings', ['free_delivery_min_cart' => 0, 'cod_max_amount' => 0, 'min_order_amount' => 0, 'delivery_fee' => 0])->assertOk();

        $order = $this->orderFor([], 5);   // would be refused by any COD limit; delivery is free because the fee is 0

        $this->assertEquals(0, $order->shipping_fee);
        $this->assertEquals(760, $order->grand_total);
    }

    public function test_the_order_number_uses_the_configured_prefix(): void
    {
        $this->setSetting('order_number_prefix', 'JHK');

        $order = $this->orderFor([], 1);

        $this->assertMatchesRegularExpression('/^JHK-\d{4}-\d{6}$/', $order->order_number);
    }
}

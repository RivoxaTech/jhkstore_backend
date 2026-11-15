<?php

namespace Tests\Feature;

use App\Domain\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Phase A of the settings: theme (developer only), store open / closed and notices, the agency credit, payment methods with bank accounts, the Delivery tab. */
class SettingsPhaseATest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function publicSettings()
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->getJson(self::STORE.'/settings')->assertOk();
    }

    // ------------------------------------------------------------------ theme

    public function test_the_theme_has_neutral_defaults_and_is_public(): void
    {
        $theme = $this->publicSettings()->json('theme');

        $this->assertSame(['primary', 'primary_contrast', 'accent', 'bg', 'surface', 'text', 'muted', 'border', 'font_heading', 'font_body', 'radius', 'shadow'], array_keys($theme));
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $theme['primary']);
        $this->assertSame('Inter', $theme['font_heading']);
        $this->assertSame('8px', $theme['radius']);
        $this->assertSame('soft', $theme['shadow']);
    }

    public function test_the_developer_changes_the_theme_and_the_storefront_reads_it(): void
    {
        $this->actingAsAdmin('developer');

        $this->putJson(self::ADMIN.'/settings', [
            'theme_primary' => '#112233', 'theme_primary_contrast' => '#ffffff', 'theme_accent' => '#FF8800', 'theme_bg' => '#FAFAFA', 'theme_surface' => '#FFFFFF',
            'theme_text' => '#000000', 'theme_muted' => '#777777', 'theme_border' => '#DDDDDD',
            'theme_font_heading' => 'Playfair Display', 'theme_font_body' => 'system-ui', 'theme_radius' => '999px', 'theme_shadow' => 'none',
        ])->assertOk()->assertJsonPath('values.theme_primary', '#112233');

        $this->assertSame(
            ['primary' => '#112233', 'primary_contrast' => '#ffffff', 'accent' => '#FF8800', 'bg' => '#FAFAFA', 'surface' => '#FFFFFF', 'text' => '#000000', 'muted' => '#777777',
                'border' => '#DDDDDD', 'font_heading' => 'Playfair Display', 'font_body' => 'system-ui', 'radius' => '999px', 'shadow' => 'none'],
            $this->publicSettings()->json('theme'),
        );
    }

    public function test_theme_values_are_validated_colours_from_a_fixed_list_of_fonts_radii_and_shadows(): void
    {
        $this->actingAsAdmin('developer');

        foreach (['red', '#12345', '#GGGGGG', '112233', '#1122334', 'url(x)'] as $bad) {
            $this->putJson(self::ADMIN.'/settings', ['theme_primary' => $bad])->assertStatus(422)->assertJsonValidationErrors('theme_primary')
                ->assertJsonPath('errors.theme_primary.0', 'Enter a colour as #RRGGBB, for example #2563EB.');
        }
        $this->putJson(self::ADMIN.'/settings', ['theme_font_heading' => 'Comic Sans'])->assertStatus(422)->assertJsonValidationErrors('theme_font_heading');
        $this->putJson(self::ADMIN.'/settings', ['theme_font_body' => 'x;}body{display:none'])->assertStatus(422)->assertJsonValidationErrors('theme_font_body');
        $this->putJson(self::ADMIN.'/settings', ['theme_radius' => '5px'])->assertStatus(422)->assertJsonValidationErrors('theme_radius');
        $this->putJson(self::ADMIN.'/settings', ['theme_shadow' => 'huge'])->assertStatus(422)->assertJsonValidationErrors('theme_shadow');

        foreach (Settings::FONTS as $font) {
            $this->putJson(self::ADMIN.'/settings', ['theme_font_heading' => $font])->assertOk();
        }
        foreach (['0px', '4px', '8px', '12px', '16px', '999px'] as $radius) {
            $this->putJson(self::ADMIN.'/settings', ['theme_radius' => $radius])->assertOk();
        }
        $this->assertSame('#2563EB', app(Settings::class)->get('theme_primary'));   // refused values changed nothing
    }

    public function test_the_choice_fields_tell_the_panel_their_options(): void
    {
        $this->actingAsAdmin('developer');

        $fields = collect($this->getJson(self::ADMIN.'/settings')->assertOk()->json('tabs'))->firstWhere('key', 'theme')['sections'][0]['fields'];
        $font = collect($fields)->firstWhere('key', 'theme_font_heading');
        $this->assertSame('choice', $font['type']);
        $this->assertSame(Settings::FONTS, $font['options']);
        $this->assertSame('color', collect($fields)->firstWhere('key', 'theme_primary')['type']);
    }

    // ------------------------------------------------------------------ store

    public function test_the_announcement_bar_is_controlled_from_the_admin_panel(): void
    {
        $this->actingAsAdmin('admin');

        // off by default: nothing is shown
        $this->publicSettings()->assertOk()->assertJsonPath('announcement.enabled', false)->assertJsonPath('announcement.text', '');

        // on with a text
        $this->actingAsAdmin('admin'); $this->putJson(self::ADMIN.'/settings', ['announcement_enabled' => true, 'announcement_text' => 'Free delivery this week'])->assertOk();
        $this->publicSettings()->assertJsonPath('announcement.enabled', true)->assertJsonPath('announcement.text', 'Free delivery this week');

        // on but empty text: still nothing to show
        $this->actingAsAdmin('admin'); $this->putJson(self::ADMIN.'/settings', ['announcement_text' => ''])->assertOk();
        $this->publicSettings()->assertJsonPath('announcement.enabled', false);

        // off again with a text kept for later
        $this->actingAsAdmin('admin'); $this->putJson(self::ADMIN.'/settings', ['announcement_enabled' => false, 'announcement_text' => 'Back soon'])->assertOk();
        $this->publicSettings()->assertJsonPath('announcement.enabled', false)->assertJsonPath('announcement.text', 'Back soon');

        // the text is at most 160 characters
        $this->actingAsAdmin('admin'); $this->putJson(self::ADMIN.'/settings', ['announcement_text' => str_repeat('a', 161)])->assertStatus(422)->assertJsonValidationErrors('announcement_text');
    }

    public function test_the_store_tab_values_are_public(): void
    {
        $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/settings', [
            'store_open' => false, 'store_closed_message' => 'Back on Monday.', 'checkout_notice' => 'Orders before noon ship today.', 'delivery_eta_text' => '2 to 4 days',
        ])->assertOk();

        $public = $this->publicSettings();
        $public->assertJsonPath('store.open', false)->assertJsonPath('store.closed_message', 'Back on Monday.')
            ->assertJsonPath('checkout.notice', 'Orders before noon ship today.')->assertJsonPath('checkout.eta_text', '2 to 4 days');
    }

    public function test_the_store_tab_is_validated(): void
    {
        $this->actingAsAdmin('admin');

        $this->putJson(self::ADMIN.'/settings', ['checkout_notice' => str_repeat('n', 301)])->assertStatus(422)->assertJsonValidationErrors('checkout_notice');
        $this->putJson(self::ADMIN.'/settings', ['store_closed_message' => str_repeat('n', 301)])->assertStatus(422)->assertJsonValidationErrors('store_closed_message');
        $this->putJson(self::ADMIN.'/settings', ['delivery_eta_text' => str_repeat('n', 121)])->assertStatus(422)->assertJsonValidationErrors('delivery_eta_text');
        $this->putJson(self::ADMIN.'/settings', ['store_open' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('store_open');
        $this->putJson(self::ADMIN.'/settings', ['checkout_notice' => str_repeat('n', 300)])->assertOk();
    }

    public function test_a_closed_store_still_quotes_but_takes_no_orders(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('store_open', false);
        $this->setSetting('store_closed_message', 'We are closed until Monday.');

        $this->quote([$variant => 1])->assertOk()->assertJsonPath('data.can_checkout', true);
        $res = $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422);

        $res->assertJsonPath('code', 'store_closed')->assertJsonPath('message', 'We are closed until Monday.');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);

        $this->setSetting('store_closed_message', '');
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'store_closed');   // a default message

        $this->setSetting('store_open', true);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
    }

    public function test_an_order_placed_before_closing_can_still_be_replayed_with_its_idempotency_key(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $body = $this->orderBody([$variant => 1]);
        $first = $this->placeOrder($body, ['Idempotency-Key' => 'closing-time-0001'])->assertCreated()->json('data.order_number');

        $this->setSetting('store_open', false);

        $this->placeOrder($body, ['Idempotency-Key' => 'closing-time-0001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.order_number', $first);
    }

    // ------------------------------------------------------------------ agency

    public function test_the_agency_credit_is_public_and_the_link_must_be_https(): void
    {
        $this->actingAsAdmin('admin');
        foreach (['http://agency.example', 'javascript:alert(1)', 'ftp://agency.example', 'agency.example'] as $bad) {
            $this->putJson(self::ADMIN.'/settings', ['agency_url' => $bad])->assertStatus(422)->assertJsonValidationErrors('agency_url');
        }

        $this->putJson(self::ADMIN.'/settings', ['agency_name' => 'Example Agency', 'agency_url' => 'https://agency.example'])->assertOk();

        $this->publicSettings()->assertJsonPath('footer.agency', ['name' => 'Example Agency', 'url' => 'https://agency.example', 'logo_url' => null]);
        $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/settings', ['agency_name' => '', 'agency_url' => ''])->assertOk();   // empty = no credit
        $this->publicSettings()->assertJsonPath('footer.agency', ['name' => '', 'url' => '', 'logo_url' => null]);
    }

    // ------------------------------------------------------------------ payments

    public function test_the_payment_methods_are_public_with_the_active_accounts_in_order(): void
    {
        $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/settings', [
            'pay_bank_enabled' => true, 'pay_card_visible' => true, 'pay_bank_instructions' => 'Use your order number as the reference.',
            'pay_bank_accounts' => [
                ['bank' => 'First Bank', 'title' => 'Shop Ltd', 'number' => '111', 'iban' => '', 'active' => true],
                ['bank' => 'Hidden Bank', 'title' => '', 'number' => '222', 'iban' => '', 'active' => false],
                ['bank' => 'Third Bank', 'title' => 'Shop Ltd', 'number' => '', 'iban' => 'DE89370400440532013000', 'active' => true],
            ],
        ])->assertOk();

        $payment = $this->publicSettings()->json('payment');

        $this->assertSame(['enabled' => true], $payment['cod']);
        $this->assertTrue($payment['bank_transfer']['enabled']);
        $this->assertSame('Use your order number as the reference.', $payment['bank_transfer']['instructions']);
        $this->assertSame(['First Bank', 'Third Bank'], array_column($payment['bank_transfer']['accounts'], 'bank'));   // inactive hidden, order kept
        $this->assertSame(['bank', 'title', 'number', 'iban'], array_keys($payment['bank_transfer']['accounts'][0]));
        $this->assertSame(['visible' => true, 'coming_soon' => true], $payment['card']);
    }

    public function test_a_fresh_store_offers_cash_on_delivery_only(): void
    {
        $payment = $this->publicSettings()->json('payment');

        $this->assertTrue($payment['cod']['enabled']);
        $this->assertFalse($payment['bank_transfer']['enabled']);
        $this->assertSame([], $payment['bank_transfer']['accounts']);
        $this->assertSame(['visible' => false, 'coming_soon' => true], $payment['card']);
    }

    public function test_bank_accounts_are_validated_like_the_links(): void
    {
        $this->actingAsAdmin('admin');
        $account = ['bank' => 'Bank', 'title' => 'T', 'number' => '1', 'iban' => '', 'active' => true];

        $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => [['bank' => '', 'number' => '1'] + $account]])->assertStatus(422)->assertJsonValidationErrors('pay_bank_accounts.0.bank');
        $res = $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => [['number' => '', 'iban' => ''] + $account]])->assertStatus(422)
            ->assertJsonValidationErrors(['pay_bank_accounts.0.number', 'pay_bank_accounts.0.iban']);
        $this->assertSame('Enter the account number or the IBAN.', $res->json('errors')['pay_bank_accounts.0.number'][0]);
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => [['bank' => str_repeat('b', 81)] + $account]])->assertStatus(422)->assertJsonValidationErrors('pay_bank_accounts.0.bank');
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => 'not a list'])->assertStatus(422)->assertJsonValidationErrors('pay_bank_accounts');
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => [['iban' => 'DE89'] + $account, ['number' => '', 'iban' => 'x', 'bank' => 'B2']]])->assertOk();   // any number of accounts
    }

    public function test_the_accounts_setting_keeps_only_well_formed_rows_in_order(): void
    {
        $settings = app(Settings::class);
        $settings->set(['pay_bank_accounts' => [
            ['bank' => 'B1', 'number' => '1'], ['bank' => '', 'number' => '2'], ['bank' => 'B3', 'number' => '', 'iban' => ''], 'junk', ['bank' => 'B4', 'iban' => 'X4', 'active' => false],
        ]]);

        $this->assertSame([
            ['bank' => 'B1', 'title' => '', 'number' => '1', 'iban' => '', 'active' => true],
            ['bank' => 'B4', 'title' => '', 'number' => '', 'iban' => 'X4', 'active' => false],
        ], $settings->get('pay_bank_accounts'));
        $this->assertSame([], $settings->accountsFrom('not json'));
    }

    public function test_at_least_one_payment_method_stays_on(): void
    {
        $this->actingAsAdmin('admin');

        $this->putJson(self::ADMIN.'/settings', ['pay_cod_enabled' => false])->assertStatus(422)->assertJsonValidationErrors('pay_cod_enabled')
            ->assertJsonPath('errors.pay_cod_enabled.0', 'Keep at least one payment method on: cash on delivery or bank transfer.');
        $this->putJson(self::ADMIN.'/settings', ['pay_cod_enabled' => false, 'pay_bank_enabled' => false])->assertStatus(422);
        $this->assertTrue(app(Settings::class)->get('pay_cod_enabled'));

        // bank transfer on with an account: cash on delivery may go
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_enabled' => true, 'pay_cod_enabled' => false, 'pay_bank_accounts' => [['bank' => 'B', 'number' => '1', 'active' => true]]])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_enabled' => false])->assertStatus(422)->assertJsonValidationErrors('pay_bank_enabled');
        $this->putJson(self::ADMIN.'/settings', ['pay_cod_enabled' => true, 'pay_bank_enabled' => false])->assertOk();
    }

    public function test_bank_transfer_needs_an_active_account(): void
    {
        $this->actingAsAdmin('admin');

        $this->putJson(self::ADMIN.'/settings', ['pay_bank_enabled' => true])->assertStatus(422)->assertJsonValidationErrors('pay_bank_enabled');
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_enabled' => true, 'pay_bank_accounts' => [['bank' => 'B', 'number' => '1', 'active' => false]]])->assertStatus(422);
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_enabled' => true, 'pay_bank_accounts' => [['bank' => 'B', 'number' => '1', 'active' => true]]])->assertOk();

        // the last active account cannot be switched off while bank transfer is on
        $this->putJson(self::ADMIN.'/settings', ['pay_bank_accounts' => []])->assertStatus(422)->assertJsonValidationErrors('pay_bank_accounts');
        $this->assertTrue(app(Settings::class)->get('pay_bank_enabled'));
    }

    // ------------------------------------------------------------------ delivery tab

    public function test_the_delivery_settings_moved_to_their_own_tab_with_their_own_permission(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['settings.orders_delivery']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);

        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['orders_delivery'], array_column($res->json('tabs'), 'key'));   // the tab is technical as a whole: its own switch opens it
        $this->putJson(self::ADMIN.'/settings', ['order_number_prefix' => 'WEB'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['min_order_amount' => 10])->assertForbidden();   // the minimum order lives in the Delivery tab

        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['settings.orders_delivery', 'settings.advanced']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['orders_delivery'], array_column($res->json('tabs'), 'key'));
        $this->assertArrayNotHasKey('delivery_fee', $res->json('values'));         // the delivery charge is in the Delivery tab
        $this->putJson(self::ADMIN.'/settings', ['order_number_prefix' => 'WEB'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['delivery_fee' => 9])->assertForbidden();
        $this->putJson(self::ADMIN.'/settings', ['delivery_days' => 9])->assertForbidden();

        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['settings.delivery']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['delivery'], array_column($res->json('tabs'), 'key'));
        // the client fields only: delivery time in days and the unmatched-address policy are technical
        $this->assertSame(['delivery_method', 'delivery_fee', 'free_delivery_min_cart', 'min_order_amount', 'delivery_eta_text'], array_column($res->json('tabs.0.sections.0.fields'), 'key'));
        $this->putJson(self::ADMIN.'/settings', ['delivery_fee' => 9, 'delivery_method' => 'zones', 'min_order_amount' => 1, 'delivery_eta_text' => '2 to 4 days'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['delivery_unmatched' => 'owner_confirms'])->assertForbidden();
        $this->putJson(self::ADMIN.'/settings', ['order_number_prefix' => 'X'])->assertForbidden();
    }

    public function test_delivery_method_and_unmatched_policy_must_be_known_values(): void
    {
        $this->actingAsAdmin('admin');

        foreach (Settings::DELIVERY_METHODS as $method) {
            $this->putJson(self::ADMIN.'/settings', ['delivery_method' => $method])->assertOk();
        }
        $this->putJson(self::ADMIN.'/settings', ['delivery_method' => 'drone'])->assertStatus(422)->assertJsonValidationErrors('delivery_method');
        $this->putJson(self::ADMIN.'/settings', ['delivery_unmatched' => 'ignore'])->assertStatus(422)->assertJsonValidationErrors('delivery_unmatched');
        $this->publicSettings()->assertJsonPath('delivery.method', 'owner_confirms');
    }

    public function test_the_default_delivery_method_keeps_the_flat_fee_with_free_delivery_above(): void
    {
        $this->assertSame('free_above', app(Settings::class)->get('delivery_method'));
        $this->assertSame('block', app(Settings::class)->get('delivery_unmatched'));
    }
}

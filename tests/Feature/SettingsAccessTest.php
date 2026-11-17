<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Settings;
use App\Http\Controllers\Admin\SettingController;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Phase C: every setting has a tier (`client` or `advanced`); a field is visible and writable only with the permission of its tab AND, for an advanced
 * field, `settings.advanced` (on for the developer, off for the admin until the developer switches it on).
 */
class SettingsAccessTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private function adminWith(array $permissions): Admin
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => $permissions])->assertOk();

        return $this->actingAsAdmin('admin', everything: false);
    }

    private function keysOf(array $payload): array
    {
        return collect($payload['tabs'])->flatMap(fn ($t) => $t['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('key')->all();
    }

    // ------------------------------------------------------------------ the tier map

    public function test_every_setting_has_a_tier_and_the_map_matches_the_spec(): void
    {
        $client = ['store_email', 'store_phone', 'store_address', 'support_hours', 'whatsapp_number', 'store_open', 'store_closed_message', 'checkout_notice', 'announcement_enabled', 'announcement_text', 'delivery_eta_text',
            'delivery_method', 'delivery_fee', 'free_delivery_min_cart', 'min_order_amount', 'pay_cod_enabled', 'pay_bank_enabled', 'pay_bank_proof_required', 'pay_card_visible', 'pay_bank_instructions', 'pay_bank_accounts',
            'mail_enabled', 'mail_on_placed', 'mail_on_confirmed', 'mail_on_shipped', 'mail_on_delivered', 'mail_on_cancelled', 'mail_on_payment_proof_rejected', 'mail_on_fee_confirmed',
            'owner_mail_on_placed', 'owner_mail_on_proof', 'owner_mail_on_wholesale', 'push_enabled', 'push_on_placed', 'push_on_proof', 'push_on_wholesale', 'rebuild_enabled'];
        $advanced = ['store_name', 'store_tagline', 'store_country', 'default_phone_country_code', 'order_number_prefix', 'cod_max_amount', 'currency_code', 'currency_symbol', 'currency_decimals',
            'tax_rate', 'tax_inclusive', 'tax_label', 'delivery_unmatched', 'delivery_days', 'mail_from_name', 'mail_reply_to', 'notify_admin_email', 'storefront_track_url',
            'rebuild_hook_url', 'rebuild_hook_secret', 'rebuild_debounce_seconds',
            'mail_custom', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address',
            'social_links', 'footer_text', 'footer_copyright', 'agency_name', 'agency_url', 'agency_logo', 'seo_meta_title', 'seo_meta_description', 'seo_share_image', 'store_logo', 'store_favicon'];

        foreach ($client as $key) {
            $this->assertSame(Settings::CLIENT, Settings::access($key), "$key should be client");
        }
        foreach ($advanced as $key) {
            $this->assertSame(Settings::ADVANCED, Settings::access($key), "$key should be advanced");
        }
        foreach (array_keys(Settings::DEFINITIONS) as $key) {
            if (str_starts_with($key, 'theme_')) {
                $this->assertSame(Settings::ADVANCED, Settings::access($key));
            } else {
                $this->assertContains($key, array_merge($client, $advanced), "$key has no entry in the map of this test: decide its tier");
            }
        }
    }

    public function test_the_docs_list_every_setting_with_its_tier_and_permission(): void
    {
        $docs = file_get_contents(base_path('docs/ACCESS_CONTROL.md'));
        foreach (Settings::DEFINITIONS as $key => $def) {
            $this->assertMatchesRegularExpression('/\|\s*`'.$key.'`\s*\|[^\n]*\|\s*(client|advanced)\s*\|\s*`settings\.'.SettingController::tabOf($key).'`/', $docs, "docs/ACCESS_CONTROL.md has no row for $key");
        }
    }

    public function test_the_default_admin_has_no_technical_access(): void
    {
        $this->assertNotContains('settings.advanced', AdminAccess::ADMIN_DEFAULTS);
        foreach (['settings.footer', 'settings.branding', 'settings.banners', 'settings.seo', 'settings.currency_tax', 'settings.orders_delivery', 'settings.theme'] as $off) {
            $this->assertNotContains($off, AdminAccess::ADMIN_DEFAULTS);
        }
        foreach (['settings.general', 'settings.store', 'settings.delivery', 'settings.payments', 'settings.notifications', 'settings.website'] as $on) {
            $this->assertContains($on, AdminAccess::ADMIN_DEFAULTS);
        }
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(AdminAccess::allows(Admin::where('username', 'developer')->firstOrFail(), 'settings.advanced'));
        $this->assertFalse(AdminAccess::allows(Admin::where('username', 'admin')->firstOrFail(), 'settings.advanced'));
    }

    // ------------------------------------------------------------------ read and write

    public function test_without_settings_advanced_the_admin_sees_and_changes_client_fields_only(): void
    {
        $this->adminWith(['settings.general', 'settings.delivery', 'settings.payments', 'settings.store', 'settings.notifications']);

        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $keys = $this->keysOf($res->json());
        $this->assertEqualsCanonicalizing(['general', 'store', 'delivery', 'payments', 'notifications'], collect($res->json('tabs'))->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['store_email', 'store_phone', 'store_address', 'support_hours', 'whatsapp_number'], array_values(array_filter($keys, fn ($k) => Settings::DEFINITIONS[$k][0] === 'general')));
        foreach ($keys as $key) {
            $this->assertSame(Settings::CLIENT, Settings::access($key), "$key must not be sent to an admin without settings.advanced");
            $this->assertArrayHasKey($key, $res->json('values'));
        }
        foreach (['store_name', 'delivery_days', 'delivery_unmatched', 'mail_from_name', 'notify_admin_email', 'storefront_track_url', 'cod_max_amount', 'currency_code'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $res->json('values'));
            $this->assertNotContains($hidden, $keys);
        }
        // every field says its tier
        $this->assertSame(['client'], collect($res->json('tabs'))->flatMap(fn ($t) => $t['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('access')->unique()->values()->all());

        $this->putJson(self::ADMIN.'/settings', ['store_email' => 'shop@example.test', 'delivery_fee' => 4])->assertOk();
        foreach (['store_name' => 'X', 'delivery_days' => 2, 'delivery_unmatched' => 'flat', 'notify_admin_email' => 'o@example.test', 'mail_from_name' => 'X', 'cod_max_amount' => 1, 'storefront_track_url' => 'https://a.test/t'] as $key => $value) {
            $this->putJson(self::ADMIN.'/settings', [$key => $value])->assertForbidden();
        }
    }

    public function test_a_request_mixing_allowed_and_forbidden_keys_changes_nothing(): void
    {
        $this->adminWith(['settings.general', 'settings.delivery']);
        $before = app(Settings::class)->all();

        $this->putJson(self::ADMIN.'/settings', ['store_email' => 'mixed@example.test', 'delivery_fee' => 12, 'store_name' => 'Hijacked'])->assertForbidden();   // store_name is advanced
        $this->putJson(self::ADMIN.'/settings', ['store_email' => 'mixed@example.test', 'theme_primary' => '#000000'])->assertForbidden();                    // another tab

        app(Settings::class)->flush();
        $this->assertSame($before, app(Settings::class)->all());
    }

    public function test_with_settings_advanced_the_technical_fields_work_and_are_tagged(): void
    {
        $this->adminWith(['settings.general', 'settings.delivery', 'settings.notifications', 'settings.advanced']);

        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $keys = $this->keysOf($res->json());
        foreach (['store_name', 'store_tagline', 'delivery_days', 'delivery_unmatched', 'mail_from_name', 'notify_admin_email', 'storefront_track_url'] as $k) {
            $this->assertContains($k, $keys);
        }
        $tiers = collect($res->json('tabs'))->flatMap(fn ($t) => $t['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('access', 'key');
        $this->assertSame('advanced', $tiers['store_name']);
        $this->assertSame('client', $tiers['store_email']);

        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'New Name', 'delivery_days' => 3, 'notify_admin_email' => 'o@example.test'])->assertOk();
        $this->assertSame('New Name', app(Settings::class)->get('store_name'));
    }

    public function test_the_theme_stays_with_the_developer_even_with_every_switch_on(): void
    {
        $admin = $this->actingAsAdmin('admin');   // every switch the admin can have, including settings.advanced
        $this->assertTrue(AdminAccess::allows($admin, 'settings.advanced'));
        $this->assertFalse(AdminAccess::allows($admin, 'settings.theme'));
        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertNotContains('theme', collect($res->json('tabs'))->pluck('key')->all());
        $this->putJson(self::ADMIN.'/settings', ['theme_primary' => '#123456'])->assertForbidden();

        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['theme_primary' => '#123456'])->assertOk();
    }

    public function test_a_technical_tab_opens_with_its_own_switch_alone(): void
    {
        // the developer switches the tabs on in Access Control: that is the decision, no second master switch is needed
        $this->adminWith(['settings.footer', 'settings.seo', 'settings.branding', 'settings.currency_tax', 'settings.orders_delivery', 'settings.banners', 'settings.mail']);
        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertSame(['branding', 'banners', 'footer', 'currency_tax', 'orders_delivery', 'mail', 'seo'], collect($res->json('tabs'))->pluck('key')->all());
        $this->getJson(self::ADMIN.'/banners')->assertOk();                           // the banner manager too
        $this->postJson(self::ADMIN.'/settings/logo', [])->assertStatus(422);         // allowed in: validation answers
        $this->putJson(self::ADMIN.'/settings', ['currency_symbol' => 'Rs.'])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'Hello'])->assertOk();

        // a single technical field inside a client tab still needs settings.advanced
        $this->adminWith(['settings.general']);
        $this->assertNotContains('store_name', $this->keysOf($this->getJson(self::ADMIN.'/settings')->json()));
        $this->putJson(self::ADMIN.'/settings', ['store_name' => 'Renamed'])->assertForbidden();
    }

    public function test_the_logo_upload_needs_the_branding_switch(): void
    {
        Storage::fake('public');
        $this->adminWith(['settings.general']);
        $this->postJson(self::ADMIN.'/settings/logo', [])->assertForbidden();
        $this->deleteJson(self::ADMIN.'/settings/favicon')->assertForbidden();

        $this->adminWith(['settings.branding']);
        $this->postJson(self::ADMIN.'/settings/logo', [])->assertStatus(422);   // allowed in: validation answers
    }

    public function test_the_failed_jobs_list_and_the_technical_status_need_settings_advanced(): void
    {
        \DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Mail\\OrderPlacedMail', 'order' => 'ORD-SECRET-9']), 'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Connection refused for secret@example.test\n#0 trace", 'failed_at' => now()]);

        $this->adminWith(['settings.notifications']);
        $status = $this->getJson(self::ADMIN.'/notifications/status')->assertOk();
        $status->assertJsonPath('failed_jobs', 1)->assertJsonStructure(['queue' => ['state'], 'mail_enabled', 'push' => ['enabled', 'configured', 'devices']]);
        foreach (['queue_connection', 'mailer', 'from_address', 'pending_jobs'] as $technical) {
            $this->assertArrayNotHasKey($technical, $status->json());
        }
        $this->getJson(self::ADMIN.'/notifications/failed-jobs')->assertForbidden();

        $this->adminWith(['settings.notifications', 'settings.advanced']);
        $this->getJson(self::ADMIN.'/notifications/status')->assertJsonStructure(['queue_connection', 'mailer', 'from_address', 'pending_jobs']);
        $list = $this->getJson(self::ADMIN.'/notifications/failed-jobs')->assertOk();
        $list->assertJsonPath('total', 1)->assertJsonPath('items.0.job', 'OrderPlacedMail')->assertJsonPath('items.0.exception', 'Symfony\\Component\\Mailer\\Exception\\TransportException');
        $this->assertStringNotContainsString('SECRET', $list->getContent());
        $this->assertStringNotContainsString('secret@example.test', $list->getContent());
    }

    // ------------------------------------------------------------------ permissions on an existing database

    public function test_sync_adds_the_new_permission_without_touching_what_the_developer_switched(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Role::findByName('admin', 'admin');
        $developer = Role::findByName('developer', 'admin');
        // an older database: the permission does not exist yet, the admin has the old defaults plus what the developer added by hand
        $admin->revokePermissionTo('settings.general');
        $admin->givePermissionTo(['settings.footer', 'settings.seo']);
        Permission::findByName('settings.advanced', 'admin')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        AdminAccess::sync();

        $names = fn (Role $r) => $r->fresh()->permissions->pluck('name')->all();
        $this->assertContains('settings.advanced', $names($developer));                   // the developer gets it (fixed on)
        $this->assertNotContains('settings.advanced', $names($admin));                    // the admin does not: it is not in the admin defaults
        $this->assertContains('settings.footer', $names($admin));                         // the developer's switches stay
        $this->assertContains('settings.seo', $names($admin));
        $this->assertNotContains('settings.general', $names($admin));
    }

    public function test_the_developer_cannot_switch_settings_advanced_off(): void
    {
        $developer = $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => []])->assertOk();
        $developer->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue(AdminAccess::allows($developer, 'settings.advanced'));
        $this->assertContains('settings.advanced', Role::findByName('developer', 'admin')->fresh()->permissions->pluck('name')->all());
    }

    // ------------------------------------------------------------------ the Access Control screen

    public function test_the_access_control_screen_says_what_each_permission_covers_and_who_it_is_for(): void
    {
        $this->actingAsAdmin('developer');
        $roles = collect($this->getJson(self::ADMIN.'/access-control')->assertOk()->json('roles'))->keyBy('role');
        $perms = collect($roles['admin']['groups'])->flatMap(fn ($g) => $g['permissions'])->keyBy('key');

        foreach ($perms as $key => $p) {
            $this->assertContains($p['tier'], ['client', 'advanced'], "$key has no tag");
            $this->assertNotEmpty($p['covers'], "$key covers nothing?");
        }
        $this->assertSame('client', $perms['settings.delivery']['tier']);
        $this->assertSame('client', $perms['settings.general']['tier']);               // mixed tab: contact fields are the client's
        $this->assertSame('advanced', $perms['settings.footer']['tier']);
        $this->assertSame('advanced', $perms['settings.seo']['tier']);
        $this->assertSame('advanced', $perms['settings.banners']['tier']);
        $this->assertSame('advanced', $perms['settings.advanced']['tier']);
        $this->assertSame('client', $perms['orders.view']['tier']);

        $general = collect($perms['settings.general']['fields'])->pluck('tier', 'key');
        $this->assertSame('client', $general['store_email']);
        $this->assertSame('advanced', $general['store_name']);
        $this->assertContains('store_name', collect($perms['settings.advanced']['fields'])->pluck('key')->all());
        $this->assertNotContains('store_email', collect($perms['settings.advanced']['fields'])->pluck('key')->all());

        $this->assertTrue($roles['developer']['groups'] !== []);
        $developerPerms = collect($roles['developer']['groups'])->flatMap(fn ($g) => $g['permissions'])->keyBy('key');
        $this->assertTrue($developerPerms['settings.advanced']['locked']);             // fixed on for the developer
    }
}

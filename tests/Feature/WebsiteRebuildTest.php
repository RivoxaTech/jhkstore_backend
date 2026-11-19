<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Support\Settings;
use App\Domain\Website\Rebuild;
use App\Domain\Website\TriggerRebuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The rebuild hook of the static storefront: what triggers it, the debounce, failures, the secret and who may do what. */
class WebsiteRebuildTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private const HOOK = 'https://api.cloudflare.example/client/v4/pages/webhooks/deploy_hooks/abc123-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        Cache::flush();
    }

    private function enable(array $extra = []): void
    {
        app(Settings::class)->set($extra + ['rebuild_enabled' => true, 'rebuild_hook_url' => self::HOOK, 'rebuild_debounce_seconds' => 120]);
    }

    private function jobs(): int
    {
        return count(Queue::pushedJobs()[TriggerRebuild::class] ?? []);
    }

    // ------------------------------------------------------------------ what triggers a rebuild

    public function test_storefront_changes_in_the_admin_panel_trigger_one_queued_job(): void
    {
        Queue::fake();
        $this->enable();
        $this->actingAsAdmin('developer');
        $category = $this->makeCategory('Toys');

        $this->postJson(self::ADMIN.'/categories', ['name' => 'Puzzles'])->assertCreated();                       // category
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Seasonal', 'slug' => 'seasonal'])->assertCreated();    // tag
        $this->putJson(self::ADMIN.'/settings', ['theme_primary' => '#0F766E'])->assertOk();                      // theme (public setting)
        $this->postJson(self::ADMIN.'/products', $this->simplePayload($category))->assertCreated();              // product

        Queue::assertPushed(TriggerRebuild::class, 1);                                                             // one job for four edits
        $this->assertSame(4, app(Rebuild::class)->pending());
    }

    public function test_twenty_quick_edits_make_one_call_to_the_hook(): void
    {
        Queue::fake();
        $this->enable();
        $this->actingAsAdmin('developer');

        for ($i = 0; $i < 20; $i++) {
            $this->putJson(self::ADMIN.'/settings', ['footer_text' => "Edit $i"])->assertOk();
        }
        $this->assertSame(1, $this->jobs());
        $this->assertSame(20, app(Rebuild::class)->pending());
        $this->assertTrue(app(Rebuild::class)->status()['waiting']);

        // the job runs once: one POST, the counter is back to 0 and the window is open again
        Http::fake([self::HOOK => Http::response(['ok' => true], 200)]);
        (new TriggerRebuild)->withFakeQueueInteractions()->handle(app(Rebuild::class));
        Http::assertSentCount(1);
        $this->assertSame(0, app(Rebuild::class)->pending());
        $this->assertTrue(app(Rebuild::class)->status()['last_result']['ok']);
        $this->assertFalse(app(Rebuild::class)->status()['waiting']);
    }

    public function test_the_job_is_delayed_by_the_debounce_window(): void
    {
        Queue::fake();
        $this->enable(['rebuild_debounce_seconds' => 300]);
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'x'])->assertOk();

        Queue::assertPushed(TriggerRebuild::class, fn (TriggerRebuild $job) => $job->delay !== null && now()->diffInSeconds($job->delay, false) > 250);
    }

    public function test_orders_customers_carts_wholesale_and_sales_never_trigger_it(): void
    {
        Queue::fake();
        $this->enable();
        $variant = $this->variantOf($this->shopProduct());

        // a guest places an order (stock goes down), uses the cart and sends a wholesale request
        $this->placeOrder($this->orderBody([$variant => 2]))->assertCreated();
        $token = $this->postJson(self::STORE.'/cart/items', ['variant_id' => $variant, 'qty' => 1])->json('data.token');
        $this->assertNotEmpty($token);
        $this->postJson(self::STORE.'/wholesale-requests', ['name' => 'Bulk Buyer', 'phone' => '+1 555 777 0000', 'product_name' => 'Anything', 'quantity' => 500])->assertCreated();

        // the admin works on orders, customers and wholesale
        $this->actingAsAdmin('admin');
        $order = \App\Domain\Order\Models\Order::query()->latest('id')->firstOrFail();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->patchJson(self::ADMIN."/orders/{$order->id}/note", ['admin_note' => 'call first'])->assertOk();
        $this->getJson(self::ADMIN.'/customers')->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, app(Rebuild::class)->pending());
    }

    public function test_notification_and_website_settings_do_not_trigger_it(): void
    {
        Queue::fake();
        $this->enable();
        $this->actingAsAdmin('developer');

        $this->putJson(self::ADMIN.'/settings', ['mail_enabled' => false, 'push_on_placed' => true, 'rebuild_debounce_seconds' => 200])->assertOk();
        Queue::assertNothingPushed();
        $this->putJson(self::ADMIN.'/settings', ['store_open' => false])->assertOk();   // the store status is public
        Queue::assertPushed(TriggerRebuild::class, 1);
    }

    public function test_a_failed_admin_write_triggers_nothing_and_a_disabled_rebuild_triggers_nothing(): void
    {
        Queue::fake();
        $this->enable();
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['theme_primary' => 'not-a-colour'])->assertStatus(422);
        Queue::assertNothingPushed();

        $this->enable(['rebuild_enabled' => false]);
        $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'quiet'])->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_banner_discount_and_picture_changes_trigger_it(): void
    {
        Queue::fake();
        $this->enable();
        $this->actingAsAdmin('developer');

        $this->postJson(self::ADMIN.'/discounts', ['name' => 'Spring', 'type' => 'percent', 'value' => 10, 'scope' => 'store'])->assertCreated();
        $this->post(self::ADMIN.'/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json'])->assertOk();
        $this->post(self::ADMIN.'/banners', ['placement' => 'home_hero', 'title' => 'Hi', 'desktop_image' => UploadedFile::fake()->image('b.jpg', 1600, 600)], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(3, app(Rebuild::class)->pending());
        Queue::assertPushed(TriggerRebuild::class, 1);
    }

    // ------------------------------------------------------------------ the call itself

    public function test_the_job_posts_an_empty_request_with_a_ten_second_timeout_and_the_secret_header(): void
    {
        $this->enable(['rebuild_hook_secret' => 'top-secret-value']);
        Http::fake([self::HOOK => Http::response('', 200)]);

        $result = app(Rebuild::class)->ping();

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST' && $request->url() === self::HOOK && $request->body() === ''
                && $request->hasHeader('X-Rebuild-Secret', 'top-secret-value');
        });
    }

    public function test_the_job_has_three_attempts_with_a_backoff_and_a_failure_is_recorded_without_leaking_the_address(): void
    {
        $this->enable(['rebuild_hook_secret' => 'top-secret-value']);
        Cache::forever(Rebuild::PENDING, 3);
        Http::fake([self::HOOK => Http::response('boom', 500)]);

        $job = new TriggerRebuild;
        $this->assertSame(3, $job->tries);
        $this->assertCount(2, $job->backoff());
        $this->assertGreaterThan(0, $job->backoff()[0]);

        try {
            $job->withFakeQueueInteractions()->handle(app(Rebuild::class));
            $this->fail('A failing hook must make the job fail (so the queue retries it).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        $status = app(Rebuild::class)->status();
        $this->assertFalse($status['last_result']['ok']);
        $this->assertTrue($status['warning']);
        $this->assertSame(3, $status['pending_changes']);   // nothing is lost: the next attempt still has them
        $this->assertStringNotContainsString('abc123-token', json_encode($status));
        $this->assertStringNotContainsString('top-secret-value', json_encode($status));
    }

    public function test_a_connection_error_is_recorded_as_a_short_clean_message(): void
    {
        $this->enable();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds for '.self::HOOK));

        $result = app(Rebuild::class)->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('abc123-token', (string) $result['error']);
        $this->assertLessThanOrEqual(160, mb_strlen((string) $result['error']));
    }

    public function test_a_failing_hook_never_breaks_the_admin_save(): void
    {
        $this->enable();
        Http::fake(fn () => throw new \RuntimeException('hosting is down'));
        $this->actingAsAdmin('developer');
        config(['queue.default' => 'sync']);   // the job runs inside the request here, the worst case

        $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'Still saved'])->assertOk();

        $this->assertSame('Still saved', app(Settings::class)->get('footer_text'));
    }

    public function test_a_broken_queue_never_breaks_the_admin_save(): void
    {
        $this->enable();
        $this->actingAsAdmin('developer');
        config(['queue.default' => 'no-such-connection']);   // dispatching throws

        $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'Saved anyway'])->assertOk();
        $this->assertSame('Saved anyway', app(Settings::class)->get('footer_text'));
    }

    public function test_a_hook_that_is_not_https_is_refused_and_never_called(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_url' => 'http://insecure.example/hook'])->assertStatus(422)->assertJsonValidationErrors('rebuild_hook_url');
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_url' => 'ftp://x.example/hook'])->assertStatus(422);
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_url' => self::HOOK])->assertOk();

        Http::fake();
        app(Settings::class)->set(['rebuild_hook_url' => 'http://insecure.example/hook']);   // even a value written behind the validation is not called
        $this->assertFalse(app(Rebuild::class)->ping()['ok']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ the secret

    public function test_the_secret_is_stored_encrypted_and_never_returned_by_any_api(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_secret' => 'top-secret-value', 'rebuild_hook_url' => self::HOOK])->assertOk();

        $raw = \DB::table('settings')->where('key', 'rebuild_hook_secret')->value('value');
        $this->assertNotSame('top-secret-value', $raw);
        $this->assertStringNotContainsString('top-secret-value', (string) $raw);
        $this->assertSame('top-secret-value', app(Settings::class)->get('rebuild_hook_secret'));   // the server can use it

        $payloads = [
            $this->getJson(self::ADMIN.'/settings')->assertOk()->getContent(),
            $this->putJson(self::ADMIN.'/settings', ['footer_text' => 'x'])->getContent(),
            $this->getJson(self::ADMIN.'/website/status')->assertOk()->getContent(),
            $this->getJson(self::STORE.'/settings')->getContent(),
            $this->getJson(self::ADMIN.'/auth/me')->getContent(),
        ];
        foreach ($payloads as $body) {
            $this->assertStringNotContainsString('top-secret-value', $body);
        }

        $field = collect($this->getJson(self::ADMIN.'/settings')->json('tabs'))->flatMap(fn ($t) => $t['sections'])->flatMap(fn ($s) => $s['fields'])->firstWhere('key', 'rebuild_hook_secret');
        $this->assertSame('secret', $field['type']);
        $this->assertTrue($field['is_set']);
        $this->assertNull($field['value']);
        $this->assertArrayNotHasKey('rebuild_hook_secret', $this->getJson(self::ADMIN.'/settings')->json('values'));
        $this->assertTrue($this->getJson(self::ADMIN.'/website/status')->json('secret_set'));

        // an empty value clears it
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_secret' => ''])->assertOk();
        $this->assertFalse($this->getJson(self::ADMIN.'/website/status')->json('secret_set'));
    }

    public function test_the_audit_log_never_holds_the_secret_or_the_hook_token(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['rebuild_hook_secret' => 'top-secret-value', 'rebuild_hook_url' => self::HOOK, 'rebuild_enabled' => true])->assertOk();
        Http::fake([self::HOOK => Http::response('', 200)]);
        $this->postJson(self::ADMIN.'/website/rebuild')->assertOk();

        $log = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('top-secret-value', $log);
        $this->assertStringNotContainsString('abc123-token', $log);
        $changed = AuditLog::query()->where('action', 'settings.updated')->latest('id')->first();
        $this->assertSame('[changed]', $changed->changes['rebuild_hook_secret']);
        $this->assertSame('api.cloudflare.example/...', $changed->changes['rebuild_hook_url']);   // the host only: that the address changed is on record
        $this->assertTrue(AuditLog::query()->where('action', 'website.rebuild_requested')->exists());
    }

    // ------------------------------------------------------------------ the button, the status and the command

    public function test_the_status_and_the_button(): void
    {
        $this->enable();
        $this->actingAsAdmin('admin', everything: false);   // the default admin: settings.website is on, settings.advanced is off

        $status = $this->getJson(self::ADMIN.'/website/status')->assertOk()->json();
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['hook_configured']);
        $this->assertSame(0, $status['pending_changes']);
        $this->assertNull($status['last_result']);
        $this->assertNull($status['last_requested_at']);
        $this->assertStringNotContainsString('abc123', json_encode($status));

        Http::fake([self::HOOK => Http::sequence()->push('', 200)->push('no', 502)]);
        $this->postJson(self::ADMIN.'/website/rebuild')->assertOk()->assertJsonPath('result.ok', true)->assertJsonPath('last_result.ok', true);
        $this->assertNotNull($this->getJson(self::ADMIN.'/website/status')->json('last_requested_at'));

        $this->postJson(self::ADMIN.'/website/rebuild')->assertOk()->assertJsonPath('result.ok', false)->assertJsonPath('warning', true);
    }

    public function test_the_panel_can_tell_when_the_new_version_is_online(): void
    {
        $rebuild = app(Rebuild::class);
        $site = 'https://shop.example';
        $requested = now()->subMinutes(2)->toIso8601String();

        // no website address: nothing to read
        $this->assertSame('no_address', $rebuild->live($requested)['state']);

        app(Settings::class)->set(['website_url' => $site]);

        // what the site answers for /build.json, changed from step to step (a later Http::fake() would not replace the first)
        $answer = Http::response(['built_at' => now()->subHours(3)->toIso8601String()]);
        Http::fake([$site.'/build.json*' => function () use (&$answer) {
            return $answer;
        }]);

        // the site still serves the build from before the request: on its way
        $this->assertSame('building', $rebuild->live($requested)['state']);

        // a build that started after the request is online
        Cache::flush();
        $answer = Http::response(['built_at' => now()->subMinute()->toIso8601String()]);
        $live = $rebuild->live($requested);
        $this->assertSame('live', $live['state']);
        $this->assertNotNull($live['built_at']);

        // nothing was ever requested from here: whatever is online is "live"
        $this->assertSame('live', $rebuild->live(null)['state']);

        // asked more than a quarter of an hour ago and still the old build: stuck
        Cache::flush();
        $answer = Http::response(['built_at' => now()->subDay()->toIso8601String()]);
        $this->assertSame('stuck', $rebuild->live(now()->subMinutes(20)->toIso8601String())['state']);

        // no build.json (an old build, a wrong address) or an error page
        Cache::flush();
        $answer = Http::response('Not found', 404);
        $this->assertSame('unreachable', $rebuild->live($requested)['state']);
        Cache::flush();
        $answer = Http::response('<html>oops</html>', 200);
        $this->assertSame('unreachable', $rebuild->live($requested)['state']);

        // the status endpoint carries it, and a website address that is not https is refused
        $this->actingAsAdmin('developer');
        $this->getJson(self::ADMIN.'/website/status')->assertOk()->assertJsonStructure(['live' => ['state', 'built_at']]);
        $this->putJson(self::ADMIN.'/settings', ['website_url' => 'http://shop.example'])->assertStatus(422);
    }

    public function test_the_button_needs_a_hook_address(): void
    {
        $this->actingAsAdmin('admin', everything: false);
        $this->postJson(self::ADMIN.'/website/rebuild')->assertStatus(422);
    }

    public function test_the_console_command_calls_the_hook(): void
    {
        $this->assertSame(1, Artisan::call('shop:rebuild'));   // no hook set

        $this->enable();
        Http::fake([self::HOOK => Http::sequence()->push('', 200)->push('', 500)]);
        $this->assertSame(0, Artisan::call('shop:rebuild'));
        Http::assertSentCount(1);
        $this->assertTrue(AuditLog::query()->where('action', 'website.rebuild_requested')->where('actor_type', 'system')->exists());

        $this->assertSame(1, Artisan::call('shop:rebuild'));
    }

    // ------------------------------------------------------------------ who may do what

    public function test_the_tiers_of_the_website_settings(): void
    {
        $this->assertSame(Settings::CLIENT, Settings::access('rebuild_enabled'));
        foreach (['rebuild_hook_url', 'rebuild_hook_secret', 'rebuild_debounce_seconds'] as $key) {
            $this->assertSame(Settings::ADVANCED, Settings::access($key));
        }
        $this->assertContains('settings.website', AdminAccess::ADMIN_DEFAULTS);
        $this->assertContains('settings.website', AdminAccess::developerDefaults());
    }

    public function test_the_default_admin_sees_the_tab_but_not_the_technical_fields(): void
    {
        $this->enable(['rebuild_hook_secret' => 'top-secret-value']);
        $this->actingAsAdmin('admin', everything: false);

        $payload = $this->getJson(self::ADMIN.'/settings')->assertOk()->json();
        $tab = collect($payload['tabs'])->firstWhere('key', 'website');
        $this->assertNotNull($tab, 'the Website tab is on for the admin by default');
        $keys = collect($tab['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('key')->all();
        $this->assertSame(['rebuild_enabled'], $keys);

        foreach (['rebuild_hook_url' => self::HOOK, 'rebuild_hook_secret' => 'x', 'rebuild_debounce_seconds' => 30] as $key => $value) {
            $this->putJson(self::ADMIN.'/settings', [$key => $value])->assertForbidden();
        }
        $this->putJson(self::ADMIN.'/settings', ['rebuild_enabled' => false])->assertOk();
    }

    public function test_without_the_permission_the_website_endpoints_answer_403(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['dashboard.view']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $this->getJson(self::ADMIN.'/website/status')->assertForbidden();
        $this->postJson(self::ADMIN.'/website/rebuild')->assertForbidden();
    }

    public function test_the_developer_holds_the_website_permission_and_never_an_order_permission(): void
    {
        $developer = $this->actingAsAdmin('developer');
        $this->assertTrue(AdminAccess::allows($developer, 'settings.website'));
        $this->getJson(self::ADMIN.'/website/status')->assertOk();

        // the hard rule: whatever the database says, the developer cannot have an orders permission, and the switch cannot be set
        foreach (AdminAccess::all() as $permission) {
            if (str_starts_with($permission, 'orders.')) {
                $this->assertFalse(AdminAccess::allows($developer, $permission), $permission);
            }
        }
        \Spatie\Permission\Models\Role::findByName('developer', 'admin')->givePermissionTo('orders.view');   // a hand edit of the table
        $developer->unsetRelation('roles')->unsetRelation('permissions');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(AdminAccess::allows(Admin::query()->findOrFail($developer->id), 'orders.view'));
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['orders.view']])->assertStatus(422);
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\PushSender;
use App\Domain\Notification\SendPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** A recording stand-in for the push service. `$answers` = what it answers for an endpoint (default: sent). */
class FakePushSender implements PushSender
{
    /** @var list<array{endpoint:string, payload:array}> */
    public array $sent = [];

    /** @var array<string,string> */
    public array $answers = [];

    public bool $throw = false;

    public function send(array $subscription, string $payload): string
    {
        if ($this->throw) {
            throw new \RuntimeException('push service unreachable');
        }
        $this->sent[] = ['endpoint' => $subscription['endpoint'], 'payload' => json_decode($payload, true)];

        return $this->answers[$subscription['endpoint']] ?? 'sent';
    }

    public function endpoints(): array
    {
        return array_column($this->sent, 'endpoint');
    }
}

/** Admin push: subscriptions, who is notified of what (permission based, never the developer for orders), cleanup and failure handling. */
class PushTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private FakePushSender $push;

    private Admin $owner;

    private Admin $developer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
        $this->setSetting('push_enabled', true);
        config(['webpush.vapid' => ['subject' => 'mailto:shop@example.test', 'public_key' => 'PUBLIC-KEY', 'private_key' => 'PRIVATE-KEY']]);
        $this->app->instance(PushSender::class, $this->push = new FakePushSender);

        $this->developer = $this->makeAdmin('developer', 'dev');
        $this->owner = $this->makeAdmin('admin', 'owner');
        AdminAccess::setRolePermissions('admin', AdminAccess::togglable('admin'));
    }

    private function as(Admin $admin): static
    {
        $this->app['auth']->forgetGuards();
        $admin->unsetRelation('roles')->unsetRelation('permissions');
        Sanctum::actingAs($admin, ['*']);

        return $this;
    }

    private function subscription(string $endpoint = 'https://push.example.test/a'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key']];
    }

    private function subscribe(Admin $admin, string $endpoint): void
    {
        $this->as($admin)->postJson(self::ADMIN.'/push/subscribe', $this->subscription($endpoint))->assertCreated();
    }

    private function newOrder(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
    }

    // ------------------------------------------------------------------ subscribing

    public function test_the_status_tells_the_panel_whether_push_works_and_whether_this_browser_is_subscribed(): void
    {
        $this->as($this->owner);
        $res = $this->getJson(self::ADMIN.'/push/status?endpoint='.urlencode('https://push.example.test/a'))->assertOk();
        $res->assertJsonPath('enabled', true)->assertJsonPath('configured', true)->assertJsonPath('public_key', 'PUBLIC-KEY')->assertJsonPath('subscribed', false);
        $this->assertStringNotContainsString('PRIVATE-KEY', $res->getContent());

        $this->subscribe($this->owner, 'https://push.example.test/a');
        $this->getJson(self::ADMIN.'/push/status?endpoint='.urlencode('https://push.example.test/a'))->assertJsonPath('subscribed', true)->assertJsonPath('devices', 1);

        config(['webpush.vapid.private_key' => '']);
        $this->getJson(self::ADMIN.'/push/status')->assertJsonPath('configured', false)->assertJsonPath('public_key', null);
    }

    public function test_subscribing_is_validated_and_moves_the_browser_to_the_current_admin(): void
    {
        $this->as($this->owner);
        $this->postJson(self::ADMIN.'/push/subscribe', ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->postJson(self::ADMIN.'/push/subscribe', ['endpoint' => 'https://push.example.test/a'])->assertStatus(422)->assertJsonValidationErrors('keys');
        $this->postJson(self::ADMIN.'/push/subscribe', ['endpoint' => 'https://push.example.test/a', 'keys' => ['p256dh' => 'a']])->assertStatus(422)->assertJsonValidationErrors('keys.auth');

        $this->postJson(self::ADMIN.'/push/subscribe', $this->subscription())->assertCreated();
        $this->postJson(self::ADMIN.'/push/subscribe', $this->subscription())->assertCreated();   // again: still one row
        $this->assertSame(1, PushSubscription::count());

        AdminAccess::setRolePermissions('developer', ['customers.view', 'wholesale.view']);
        $this->as($this->developer)->postJson(self::ADMIN.'/push/subscribe', $this->subscription())->assertCreated();
        $this->assertSame($this->developer->id, PushSubscription::first()->admin_id);   // one browser, one admin
    }

    public function test_unsubscribing_removes_only_the_admins_own_browser(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/a');
        AdminAccess::setRolePermissions('developer', ['wholesale.view']);
        $this->subscribe($this->developer, 'https://push.example.test/b');

        $this->as($this->owner)->postJson(self::ADMIN.'/push/unsubscribe', ['endpoint' => 'https://push.example.test/b'])->assertOk();   // not hers
        $this->assertSame(2, PushSubscription::count());
        $this->postJson(self::ADMIN.'/push/unsubscribe', ['endpoint' => 'https://push.example.test/a'])->assertOk()->assertJsonPath('subscribed', false);
        $this->assertSame(['https://push.example.test/b'], PushSubscription::pluck('endpoint')->all());
    }

    public function test_the_developer_cannot_use_push_unless_wholesale_is_switched_on(): void
    {
        $this->as($this->developer);
        $this->getJson(self::ADMIN.'/push/status')->assertForbidden();
        $this->postJson(self::ADMIN.'/push/subscribe', $this->subscription())->assertForbidden();
        $this->postJson(self::ADMIN.'/push/unsubscribe', ['endpoint' => 'https://x.test'])->assertForbidden();
        $this->assertSame(0, PushSubscription::count());

        AdminAccess::setRolePermissions('developer', ['wholesale.view']);
        $this->as($this->developer)->getJson(self::ADMIN.'/push/status')->assertOk();
    }

    // ------------------------------------------------------------------ who is told what

    public function test_a_new_order_notifies_admins_who_may_see_orders_and_never_the_developer(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/owner');
        // the developer cannot subscribe through the API, but even a row written by hand gets nothing
        PushSubscription::create(['admin_id' => $this->developer->id, 'endpoint' => 'https://push.example.test/dev', 'p256dh' => 'x', 'auth' => 'y']);
        AdminAccess::setRolePermissions('developer', AdminAccess::togglable('developer'));   // even with every switch on

        $this->newOrder();

        $this->assertSame(['https://push.example.test/owner'], $this->push->endpoints());
        $message = $this->push->sent[0]['payload'];
        $this->assertSame(['title', 'body', 'url', 'tag'], array_keys($message));
        $this->assertSame('New order', $message['title']);
        $this->assertStringStartsWith('ORD-', $message['body']);
        $this->assertSame('order-'.DB::table('orders')->value('id'), $message['tag']);
        $this->assertStringContainsString('$', $message['body']);
        $this->assertSame('/admin/orders/'.DB::table('orders')->value('id'), $message['url']);
    }

    public function test_a_payment_proof_notifies_the_order_admins(): void
    {
        $order = $this->bankOrder();
        $this->subscribe($this->owner, 'https://push.example.test/owner');
        $this->push->sent = [];

        $this->uploadProof($order)->assertOk();

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('Payment proof to check', $this->push->sent[0]['payload']['title']);
        $this->assertStringContainsString($order->order_number, $this->push->sent[0]['payload']['body']);
        $this->assertNotContains('https://push.example.test/dev', $this->push->endpoints());
    }

    public function test_a_wholesale_request_notifies_admins_with_the_wholesale_permission_only(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/owner');
        AdminAccess::setRolePermissions('developer', ['wholesale.view']);
        $this->subscribe($this->developer, 'https://push.example.test/dev');

        $this->flushHeaders()->postJson(self::STORE.'/wholesale-requests', ['name' => 'Bulk Buyer', 'phone' => '+1 555 200 0001', 'product_name' => 'Boxes', 'quantity' => 500])->assertCreated();
        $this->assertEqualsCanonicalizing(['https://push.example.test/owner', 'https://push.example.test/dev'], $this->push->endpoints());
        $this->assertSame('New wholesale request', $this->push->sent[0]['payload']['title']);
        $this->assertSame('/admin/wholesale', $this->push->sent[0]['payload']['url']);

        // the developer never receives the order push, even with the wholesale switch on
        $this->push->sent = [];
        $this->newOrder();
        $this->assertSame(['https://push.example.test/owner'], $this->push->endpoints());

        // the owner's wholesale access is switched off: no more wholesale push for the owner
        $this->push->sent = [];
        AdminAccess::setRolePermissions('admin', array_values(array_diff(AdminAccess::togglable('admin'), ['wholesale.view', 'wholesale.manage'])));
        $this->flushHeaders()->postJson(self::STORE.'/wholesale-requests', ['name' => 'Bulk Buyer', 'phone' => '+1 555 200 0002', 'product_name' => 'Boxes', 'quantity' => 5])->assertCreated();
        $this->assertSame(['https://push.example.test/dev'], $this->push->endpoints());
    }

    public function test_access_switched_off_after_subscribing_stops_the_notifications(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/owner');
        AdminAccess::setRolePermissions('admin', ['dashboard.view']);   // the developer switched the orders off

        $this->newOrder();
        $this->assertSame([], $this->push->sent);

        AdminAccess::setRolePermissions('admin', AdminAccess::togglable('admin'));
        $this->owner->forceFill(['is_active' => false])->save();         // a disabled account gets nothing
        $this->newOrder();
        $this->assertSame([], $this->push->sent);
    }

    // ------------------------------------------------------------------ cleanup and failures

    public function test_a_subscription_the_push_service_no_longer_knows_is_deleted(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/gone');
        $this->subscribe($this->owner, 'https://push.example.test/ok');
        $this->subscribe($this->owner, 'https://push.example.test/flaky');
        $this->push->answers = ['https://push.example.test/gone' => 'gone', 'https://push.example.test/flaky' => 'failed'];

        $this->newOrder();

        $this->assertEqualsCanonicalizing(['https://push.example.test/ok', 'https://push.example.test/flaky'], PushSubscription::pluck('endpoint')->all());   // 404 / 410 only
        $this->assertNotNull(PushSubscription::where('endpoint', 'https://push.example.test/ok')->value('last_used_at'));
    }

    public function test_a_push_failure_never_fails_the_order(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/owner');
        $this->push->throw = true;

        $this->newOrder();                                   // the order is placed although the push service is unreachable
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, PushSubscription::count());     // an error is not a "gone": the subscription stays
    }

    public function test_nothing_is_queued_when_push_is_switched_off_or_not_configured(): void
    {
        Queue::fake();
        $this->setSetting('push_enabled', false);
        $this->newOrder();
        Queue::assertNotPushed(SendPushNotification::class);

        $this->setSetting('push_enabled', true);
        config(['webpush.vapid.public_key' => '']);
        $this->newOrder();
        Queue::assertNotPushed(SendPushNotification::class);

        config(['webpush.vapid.public_key' => 'PUBLIC-KEY']);
        $this->newOrder();
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->permission === 'orders.view' && $job->afterCommit === true);
    }

    public function test_the_vapid_command_prints_a_usable_key_pair(): void
    {
        if (! openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])) {
            $this->markTestSkipped('PHP cannot create EC keys here (on Windows set OPENSSL_CONF to the openssl.cnf of the PHP install).');
        }
        Artisan::call('shop:vapid');
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{80,}\s*$/m', $out);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{40,}\s*$/m', $out);
        $this->assertStringContainsString('VAPID_SUBJECT=', $out);
    }
}

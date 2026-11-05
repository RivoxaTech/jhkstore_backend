<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\PushSender;
use App\Domain\Notification\QueueHealth;
use App\Domain\Notification\QueueHeartbeat;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Settings;
use App\Mail\OrderFeeConfirmedMail;
use App\Mail\OwnerAlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** A push service stand-in that records the messages and answers what the test says. */
class RecordingPush implements PushSender
{
    public array $sent = [];

    public string $answer = 'sent';

    public ?\Throwable $throw = null;

    public function send(array $subscription, string $payload): string
    {
        if ($this->throw) {
            throw $this->throw;
        }
        $this->sent[] = ['endpoint' => $subscription['endpoint'], 'payload' => json_decode($payload, true)];

        return $this->answer;
    }
}

/**
 * Phase C notifications: one switch per event and channel, the push content, the test notification through the queue, the status card
 * (queue worker state, last push) and the rule that the developer never gets an order notification.
 */
class NotificationStatusTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private RecordingPush $push;

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
        $this->setSetting('mail_enabled', true);
        $this->setSetting('notify_admin_email', 'owner@shop.test');
        config(['webpush.vapid' => ['subject' => 'mailto:shop@example.test', 'public_key' => 'PUBLIC-KEY', 'private_key' => 'PRIVATE-KEY']]);
        $this->app->instance(PushSender::class, $this->push = new RecordingPush);
        Cache::flush();

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

    private function subscribe(Admin $admin, string $endpoint = 'https://push.example.test/owner'): void
    {
        PushSubscription::create(['admin_id' => $admin->id, 'endpoint' => $endpoint, 'p256dh' => 'x', 'auth' => 'y']);
    }

    private function newOrder(): Order
    {
        $variant = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $this->contact(['email' => 'buyer@example.test'])]))->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    // ------------------------------------------------------------------ one switch per event and channel

    public function test_every_event_row_names_real_switches(): void
    {
        foreach (Settings::EVENTS as $key => [$label, $customer, $owner, $push]) {
            foreach (array_filter([$customer, $owner, $push]) as $setting) {
                $this->assertSame('bool', Settings::DEFINITIONS[$setting][1], "$key: $setting");
                $this->assertSame(Settings::CLIENT, Settings::access($setting), "$setting is a client switch");
            }
            $this->assertNotEmpty(array_filter([$customer, $owner, $push]), "$key has no channel");
        }
        $this->as($this->owner);
        $events = $this->getJson(self::ADMIN.'/settings')->assertOk()->json('events');
        $this->assertSame(array_keys(Settings::EVENTS), array_column($events, 'key'));
        $this->assertSame('mail_on_placed', $events[0]['customer']);
    }

    public function test_the_owner_alert_and_the_push_of_a_new_order_follow_their_own_switches(): void
    {
        Mail::fake();
        $this->subscribe($this->owner);

        $this->newOrder();
        Mail::assertQueued(OwnerAlertMail::class, 1);
        $this->assertCount(1, $this->push->sent);

        $this->setSetting('owner_mail_on_placed', false);
        $this->newOrder();
        Mail::assertQueued(OwnerAlertMail::class, 1);            // unchanged: the owner e-mail is off for this event
        $this->assertCount(2, $this->push->sent);                // the push still goes

        $this->setSetting('push_on_placed', false);
        $this->newOrder();
        $this->assertCount(2, $this->push->sent);                // now the push is off as well
    }

    public function test_the_delivery_fee_mail_has_its_own_switch(): void
    {
        Mail::fake();
        $this->setSetting('delivery_method', 'owner_confirms');
        $order = $this->newOrder();
        $this->setSetting('mail_on_placed', false);              // the "order received" mail off must not silence the fee mail any more

        $this->as($this->owner)->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 5])->assertOk();
        Mail::assertQueued(OrderFeeConfirmedMail::class, 1);

        $order2 = $this->newOrder();
        $this->setSetting('mail_on_fee_confirmed', false);
        $this->as($this->owner)->putJson(self::ADMIN."/orders/{$order2->id}/delivery-fee", ['fee' => 5])->assertOk();
        Mail::assertQueued(OrderFeeConfirmedMail::class, 1);
    }

    public function test_the_wholesale_alerts_follow_their_switches(): void
    {
        Mail::fake();
        $this->subscribe($this->owner);
        $this->setSetting('owner_mail_on_wholesale', false);
        $this->setSetting('push_on_wholesale', false);

        $this->postJson('/api/v1/store/wholesale-requests', ['name' => 'Buyer', 'phone' => '+10000000001', 'product_name' => 'Widgets', 'quantity' => 100, 'message' => 'Price please'])->assertSuccessful();
        Mail::assertNothingQueued();
        $this->assertSame([], $this->push->sent);
    }

    // ------------------------------------------------------------------ the push message

    public function test_the_new_order_push_has_the_title_body_logo_tag_and_link(): void
    {
        $this->setSetting('currency_symbol', '$');
        app(Settings::class)->attachFile('store_logo', UploadedFile::fake()->image('logo.png', 64, 64));
        $this->subscribe($this->owner);

        $order = $this->newOrder();

        $message = $this->push->sent[0]['payload'];
        $this->assertSame('New order', $message['title']);
        $this->assertSame($order->order_number.' · $'.number_format((float) $order->grand_total, 2), $message['body']);
        $this->assertSame('order-'.$order->id, $message['tag']);
        $this->assertSame('/admin/orders/'.$order->id, $message['url']);
        $this->assertSame(app(Settings::class)->logoUrl(), $message['icon']);
    }

    public function test_the_developer_never_gets_an_order_notification_even_with_every_switch_on(): void
    {
        $this->subscribe($this->developer, 'https://push.example.test/dev');
        AdminAccess::setRolePermissions('developer', AdminAccess::togglable('developer'));

        $this->newOrder();
        $this->assertSame([], $this->push->sent);
    }

    // ------------------------------------------------------------------ test notification, through the queue

    public function test_the_test_notification_goes_to_this_admins_device_through_the_queue_and_reports(): void
    {
        $this->subscribe($this->owner, 'https://push.example.test/mine');
        $this->subscribe($this->owner, 'https://push.example.test/other-browser');
        $this->as($this->owner);

        $id = $this->postJson(self::ADMIN.'/push/test', ['endpoint' => 'https://push.example.test/mine'])->assertStatus(202)->json('id');
        $result = $this->getJson(self::ADMIN."/push/test/$id")->assertOk();

        $result->assertJsonPath('status', 'sent')->assertJsonPath('devices', 1)->assertJsonPath('sent', 1)->assertJsonPath('error', null);
        $this->assertSame(['https://push.example.test/mine'], array_column($this->push->sent, 'endpoint'));
        $this->assertSame('Test notification', $this->push->sent[0]['payload']['title']);
        $this->assertStringNotContainsString('ORD-', json_encode($this->push->sent[0]['payload']));   // no order data in a test

        // the status card shows the last push and its result
        $status = $this->getJson(self::ADMIN.'/push/status')->assertOk();
        $this->assertSame('Test notification', $status->json('last.title'));
        $this->assertSame(1, $status->json('last.sent'));
    }

    public function test_the_test_notification_reports_the_exact_error(): void
    {
        $this->subscribe($this->owner);
        $this->as($this->owner);

        $this->push->throw = new \RuntimeException('cURL error 6: Could not resolve host: fcm.googleapis.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)');
        $id = $this->postJson(self::ADMIN.'/push/test')->assertStatus(202)->json('id');
        $result = $this->getJson(self::ADMIN."/push/test/$id")->assertOk();
        $result->assertJsonPath('status', 'failed')->assertJsonPath('sent', 0);
        $this->assertStringContainsString('Could not resolve host', $result->json('error'));
        $this->assertStringNotContainsString('https://curl', $result->json('error'));   // addresses are stripped

        $this->push->throw = null;
        $this->push->answer = 'gone';
        $id = $this->postJson(self::ADMIN.'/push/test')->json('id');
        $this->getJson(self::ADMIN."/push/test/$id")->assertJsonPath('status', 'failed')->assertJsonPath('error', 'The push service says this device is no longer subscribed. Turn the bell off and on again.');
        $this->assertSame(0, PushSubscription::count());   // the dead subscription is removed
    }

    public function test_the_test_notification_without_a_device_or_without_keys(): void
    {
        $this->as($this->owner);
        $id = $this->postJson(self::ADMIN.'/push/test')->assertStatus(202)->json('id');
        $this->getJson(self::ADMIN."/push/test/$id")->assertJsonPath('status', 'no_device');

        config(['webpush.vapid.private_key' => '']);
        $this->postJson(self::ADMIN.'/push/test')->assertStatus(422)->assertJsonPath('code', 'vapid_missing');
    }

    public function test_a_test_result_is_private_and_the_test_needs_the_push_permission(): void
    {
        $this->subscribe($this->owner);
        $id = $this->as($this->owner)->postJson(self::ADMIN.'/push/test')->json('id');

        AdminAccess::setRolePermissions('developer', ['wholesale.view']);
        $this->as($this->developer)->getJson(self::ADMIN."/push/test/$id")->assertNotFound();   // somebody else's test
        AdminAccess::setRolePermissions('developer', []);
        $this->as($this->developer)->postJson(self::ADMIN.'/push/test')->assertForbidden();      // no order or wholesale permission: no push at all
        $this->assertSame(1, count($this->push->sent));
    }

    public function test_the_test_goes_through_the_real_queue_when_one_is_configured(): void
    {
        Queue::fake();
        $this->subscribe($this->owner);
        $this->as($this->owner);

        $id = $this->postJson(self::ADMIN.'/push/test')->assertStatus(202)->json('id');
        Queue::assertPushed(\App\Domain\Notification\SendTestPush::class, fn ($job) => $job->adminId === $this->owner->id && $job->testId === $id);
        $this->getJson(self::ADMIN."/push/test/$id")->assertJsonPath('status', 'queued');   // until a worker picks it up
    }

    // ------------------------------------------------------------------ is a queue worker running?

    public function test_the_queue_state_sync_idle_running_and_stopped(): void
    {
        $this->assertSame('sync', QueueHealth::status()['state']);

        config(['queue.default' => 'database']);
        $this->assertSame('idle', QueueHealth::status()['state']);           // nothing waiting, nothing seen: cannot tell

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 300, 'created_at' => time() - 300]);
        $stopped = QueueHealth::status();
        $this->assertSame('stopped', $stopped['state']);                     // a job waited 5 minutes
        $this->assertGreaterThanOrEqual(299, $stopped['oldest_pending_seconds']);

        QueueHealth::recordActivity();                                       // a worker finished a job just now
        $this->assertSame('running', QueueHealth::status()['state']);

        DB::table('jobs')->truncate();
        Cache::flush();
        QueueHealth::recordHeartbeat();
        $this->assertSame('running', QueueHealth::status()['state']);
        $this->assertNotNull(QueueHealth::status()['heartbeat_at']);
    }

    public function test_a_fresh_pending_job_is_not_a_stopped_worker(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 20, 'created_at' => time() - 20]);
        $this->assertSame('idle', QueueHealth::status()['state']);
    }

    public function test_the_heartbeat_command_queues_one_job_and_never_piles_up(): void
    {
        config(['queue.default' => 'database']);
        Artisan::call('shop:queue-heartbeat');
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%QueueHeartbeat%')->count());
        Artisan::call('shop:queue-heartbeat');
        $this->assertSame(1, DB::table('jobs')->count());                    // no worker: still just one

        Artisan::call('queue:work', ['--once' => true]);                     // a worker takes it
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull(QueueHealth::status()['heartbeat_at']);
        $this->assertSame('running', QueueHealth::status()['state']);
    }

    public function test_the_heartbeat_is_scheduled_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'shop:queue-heartbeat'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertInstanceOf(QueueHeartbeat::class, new QueueHeartbeat);
    }

    public function test_the_status_endpoint_reports_the_worker_and_the_last_push(): void
    {
        $this->subscribe($this->owner);
        $this->newOrder();
        $this->as($this->owner);

        $res = $this->getJson(self::ADMIN.'/notifications/status')->assertOk();
        $res->assertJsonPath('queue.state', 'sync')->assertJsonPath('push.configured', true)->assertJsonPath('push.enabled', true);
        $this->assertSame('New order', $res->json('push.last.title'));
        $this->assertSame(1, $res->json('push.last.sent'));
        $this->assertStringNotContainsString('ORD-', json_encode($res->json('push.last')));   // the log never keeps the body
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Order\Models\Order;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderDeliveredMail;
use App\Mail\OrderFeeConfirmedMail;
use App\Mail\OrderPlacedMail;
use App\Mail\OrderShippedMail;
use App\Mail\OwnerAlertMail;
use App\Mail\PaymentProofRejectedMail;
use App\Mail\TestMail;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Order e-mails: one queued mailable per event, the switches, the owner alerts, the content, the test e-mail, and a mail failure never failing an order. */
class OrderMailTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
        $this->setSetting('mail_enabled', true);
        $this->setSetting('notify_admin_email', 'owner@shop.test');
        $this->setSetting('storefront_track_url', 'https://shop.test/track');
        $this->setSetting('store_name', 'Corner Shop');
        $this->setSetting('store_email', 'hello@shop.test');
    }

    private function place(array $contact = [], array $extra = [], int $qty = 1): Order
    {
        $variant = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variant => $qty], ['customer' => $this->contact($contact + ['email' => 'buyer@example.test'])] + $extra))->assertCreated()->json('data.order_number');

        return Order::query()->where('order_number', $number)->firstOrFail();
    }

    private function move(Order $order, string $status, array $body = [])
    {
        $this->actingAsAdmin();

        return $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => $status] + $body);
    }

    // ------------------------------------------------------------------ one mailable per event, queued

    public function test_every_event_sends_its_own_queued_mail_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->place();
        Mail::assertQueued(OrderPlacedMail::class, fn ($m) => $m->hasTo('buyer@example.test') && $m->order->is($order));

        $this->move($order, 'confirmed')->assertOk();
        Mail::assertQueued(OrderConfirmedMail::class, 1);
        $this->move($order, 'packed')->assertOk();
        $this->move($order, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'AWB123'])->assertOk();
        Mail::assertQueued(OrderShippedMail::class, fn ($m) => $m->hasTo('buyer@example.test'));
        $this->move($order, 'delivered')->assertOk();
        Mail::assertQueued(OrderDeliveredMail::class, 1);
        Mail::assertNotQueued(OrderCancelledMail::class);

        $cancelled = $this->place();
        $this->move($cancelled, 'cancelled', ['reason' => 'Customer asked'])->assertOk();
        Mail::assertQueued(OrderCancelledMail::class, fn ($m) => $m->order->is($cancelled));

        $rejected = $this->place();
        $this->move($rejected, 'rejected', ['reason' => 'Out of the delivery area'])->assertOk();
        Mail::assertQueued(OrderCancelledMail::class, fn ($m) => $m->order->is($rejected));
    }

    public function test_all_mailables_are_queued_after_the_commit(): void
    {
        $order = $this->place();
        foreach ([OrderPlacedMail::class, OrderConfirmedMail::class, OrderShippedMail::class, OrderDeliveredMail::class, OrderCancelledMail::class, OrderFeeConfirmedMail::class,
            PaymentProofRejectedMail::class] as $class) {
            $this->assertInstanceOf(ShouldQueue::class, $mail = new $class($order), "$class must be queued");
            $this->assertTrue($mail->afterCommit, "$class must wait for the commit");
        }
        $this->assertTrue((new OwnerAlertMail('new_order', $order))->afterCommit);
        $this->assertFalse(is_subclass_of(TestMail::class, ShouldQueue::class), 'the test e-mail is sent at once');
    }

    public function test_the_payment_proof_rejection_and_the_confirmed_delivery_fee_send_mails(): void
    {
        Mail::fake();
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'Test Bank', 'title' => 'Shop', 'number' => '123', 'iban' => '', 'active' => true]]);
        $variant = $this->variantOf($this->shopProduct());
        $number = $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => 'bank_transfer', 'customer' => $this->contact(['email' => 'buyer@example.test'])]))->json('data.order_number');
        $order = Order::where('order_number', $number)->first();

        $this->uploadProof($order)->assertOk();
        Mail::assertQueued(OwnerAlertMail::class, fn ($m) => $m->kind === 'payment_proof' && $m->hasTo('owner@shop.test'));

        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'Blurry picture'])->assertOk();
        Mail::assertQueued(PaymentProofRejectedMail::class, fn ($m) => $m->hasTo('buyer@example.test'));

        $this->setSetting('delivery_method', 'owner_confirms');
        $second = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $this->contact(['email' => 'b2@example.test'])]))->json('data.order_number'))->first();
        $this->assertTrue((bool) $second->delivery_fee_pending);
        $this->actingAsAdmin();
        $this->putJson(self::ADMIN."/orders/{$second->id}/delivery-fee", ['fee' => 7])->assertOk();
        Mail::assertQueued(OrderFeeConfirmedMail::class, fn ($m) => $m->hasTo('b2@example.test'));
    }

    // ------------------------------------------------------------------ the switches

    public function test_nothing_is_sent_when_mails_are_off_or_the_event_is_off_or_the_order_has_no_email(): void
    {
        Mail::fake();

        $this->setSetting('mail_enabled', false);
        $this->place();
        Mail::assertNothingQueued();                      // the master switch also stops the owner alert

        $this->setSetting('mail_enabled', true);
        $this->setSetting('mail_on_placed', false);
        $this->place();
        Mail::assertNotQueued(OrderPlacedMail::class);
        Mail::assertQueued(OwnerAlertMail::class, 1);     // the owner alert is not an event switch

        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('mail_on_placed', true);
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => ['name' => 'No Mail', 'phone' => '+1 555 777 0001']]))->assertCreated();   // no e-mail on the order
        Mail::assertQueued(OrderPlacedMail::class, 0);

        $this->setSetting('notify_admin_email', '');
        $before = count(Mail::queued(OwnerAlertMail::class));
        $this->place();
        $this->assertCount($before, Mail::queued(OwnerAlertMail::class));   // no owner address, no alert

        foreach (['mail_on_confirmed' => 'confirmed', 'mail_on_cancelled' => 'cancelled'] as $switch => $status) {
            $this->setSetting($switch, false);
            $order = $this->place();
            $this->move($order, $status, ['reason' => 'x'])->assertOk();
        }
        Mail::assertNotQueued(OrderConfirmedMail::class);
        Mail::assertNotQueued(OrderCancelledMail::class);
    }

    public function test_every_mail_switch_is_a_setting_with_a_sensible_default(): void
    {
        $defaults = fn (string $key) => \App\Domain\Support\Settings::DEFINITIONS[$key][2];
        $this->assertFalse($defaults('mail_enabled'));   // off until the mail server is set up
        $this->assertFalse($defaults('push_enabled'));
        foreach (['mail_on_placed', 'mail_on_confirmed', 'mail_on_shipped', 'mail_on_delivered', 'mail_on_cancelled', 'mail_on_payment_proof_rejected'] as $key) {
            $this->assertTrue($defaults($key), $key);
        }
        $this->assertSame('', $defaults('notify_admin_email'));
    }

    // ------------------------------------------------------------------ content

    public function test_the_mail_has_the_order_the_totals_the_tracking_link_and_nothing_remote(): void
    {
        $order = $this->place(qty: 2);
        $html = (new OrderPlacedMail($order))->render();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Shop Item', $html);
        $this->assertStringContainsString('2 ×', $html);
        $this->assertStringContainsString('$'.number_format((float) $order->grand_total, 2), $html);
        $this->assertStringContainsString('Cash on delivery', $html);
        $this->assertStringContainsString('https://shop.test/track?order='.urlencode($order->order_number), $html);
        $this->assertStringContainsString('Corner Shop', $html);
        $this->assertStringContainsString('hello@shop.test', $html);
        $this->assertStringNotContainsString('<img', $html);               // no images, so no tracking pixel
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        preg_match_all('#https?://[^\s"\'<)]+#', $html, $urls);
        foreach ($urls[0] as $url) {
            $this->assertStringStartsWith('https://shop.test/track', $url, "unexpected remote address $url");
        }
    }

    public function test_a_bank_transfer_mail_shows_the_account_snapshot_and_the_shipped_mail_the_courier(): void
    {
        $order = $this->bankOrder();
        $this->setSetting('pay_bank_accounts', [['bank' => 'Another Bank', 'title' => 'Other', 'number' => '999', 'iban' => '', 'active' => true]]);   // changed later
        $html = (new OrderPlacedMail($order))->render();
        $this->assertStringContainsString('Test Bank', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringNotContainsString('Another Bank', $html);       // the snapshot, not the current setting

        $cod = $this->place();
        $this->move($cod, 'confirmed')->assertOk();
        $this->move($cod, 'packed')->assertOk();
        $this->move($cod, 'shipped', ['courier' => 'FastShip', 'awb_number' => 'AWB-777', 'tracking_url' => 'https://fastship.test/t/AWB-777'])->assertOk();
        $shipped = (new OrderShippedMail(Order::find($cod->id)))->render();
        $this->assertStringContainsString('FastShip', $shipped);
        $this->assertStringContainsString('AWB-777', $shipped);
        $this->assertStringContainsString('https://fastship.test/t/AWB-777', $shipped);
    }

    public function test_the_cancelled_and_rejected_mails_carry_the_reason(): void
    {
        $a = $this->place();
        $this->move($a, 'rejected', ['reason' => 'We do not deliver there'])->assertOk();
        $html = (new OrderCancelledMail(Order::find($a->id)))->render();
        $this->assertStringContainsString('We do not deliver there', $html);
        $this->assertStringContainsString('could not accept', $html);

        $b = $this->place();
        $this->move($b, 'cancelled', ['reason' => 'Changed my mind'])->assertOk();
        $this->assertStringContainsString('Changed my mind', (new OrderCancelledMail(Order::find($b->id)))->render());
    }

    public function test_the_sender_and_reply_to_come_from_the_settings(): void
    {
        config(['mail.from.address' => 'shop@example.test']);
        $order = $this->place();
        $this->setSetting('mail_from_name', 'Corner Shop Team');
        $this->setSetting('mail_reply_to', 'help@shop.test');

        $mail = new OrderPlacedMail($order);
        $envelope = $mail->envelope();
        $this->assertSame('Corner Shop Team', $envelope->from->name);
        $this->assertSame('shop@example.test', $envelope->from->address);
        $this->assertSame('help@shop.test', $envelope->replyTo[0]->address);
        $this->assertSame('We received your order · '.$order->order_number, $envelope->subject);
    }

    // ------------------------------------------------------------------ a mail failure never fails an order

    private function failingMailer(): void
    {
        $this->app->forgetInstance('mail.manager');   // drops a Mail::fake() of the same test
        Facade::clearResolvedInstances();
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new \RuntimeException('SMTP server is down');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
        Mail::purge();
    }

    public function test_a_broken_mail_server_never_fails_or_rolls_back_an_order_or_a_status_change(): void
    {
        $this->failingMailer();

        $order = $this->place();                                  // the order is placed although the mail could not be sent
        $this->assertSame('pending', $order->status->value);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(49, $this->stockOf($order->items()->first()->variant_id)->stock_qty);

        $this->move($order, 'confirmed')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->move($order, 'cancelled', ['reason' => 'x'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_a_queue_that_cannot_be_reached_never_fails_an_order(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('queue table is missing'));

        $order = $this->place();

        $this->assertSame('pending', $order->status->value);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_broken_mail_setup_does_not_fail_the_payment_proof_upload_either(): void
    {
        $order = $this->bankOrder(['email' => 'buyer@example.test']);
        $this->failingMailer();

        $this->uploadProof($order)->assertOk()->assertJsonPath('data.payment.proof_status', 'submitted');
    }

    // ------------------------------------------------------------------ the Notifications tab: test e-mail, failed jobs count

    public function test_the_test_email_is_sent_at_once_and_a_failure_is_reported(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertOk()->assertJsonPath('to', 'me@example.test');
        Mail::assertSent(TestMail::class, fn ($m) => $m->hasTo('me@example.test'));
        $this->postJson(self::ADMIN.'/notifications/test-email', [])->assertOk()->assertJsonPath('to', 'owner@shop.test');   // the owner address by default
        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->setSetting('notify_admin_email', '');
        $this->postJson(self::ADMIN.'/notifications/test-email', [])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'notifications.test_email')->count());

        $this->failingMailer();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertStatus(502)->assertJsonPath('code', 'mail_failed');
    }

    public function test_the_status_counts_the_failed_jobs_and_never_shows_their_content(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{"order":"ORD-SECRET-1"}', 'exception' => 'boom', 'failed_at' => now()]);
        DB::table('failed_jobs')->insert(['uuid' => 'b', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $this->actingAsAdmin('developer');

        $res = $this->getJson(self::ADMIN.'/notifications/status')->assertOk();
        $this->assertSame(2, $res->json('failed_jobs'));
        $this->assertSame(0, $res->json('pending_jobs'));
        $this->assertStringNotContainsString('SECRET', $res->getContent());
        $this->assertTrue($res->json('mail_enabled'));
    }

    public function test_the_notifications_endpoints_need_the_notifications_permission(): void
    {
        $this->actingAsAdmin('admin', false);
        AdminAccess::setRolePermissions('admin', ['dashboard.view']);

        $this->getJson(self::ADMIN.'/notifications/status')->assertForbidden();
        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertForbidden();
        $this->putJson(self::ADMIN.'/settings', ['mail_enabled' => true])->assertForbidden();

        AdminAccess::setRolePermissions('admin', ['settings.notifications']);
        $this->putJson(self::ADMIN.'/settings', ['mail_enabled' => false, 'notify_admin_email' => 'new@shop.test', 'mail_on_shipped' => false])->assertOk();
        $this->putJson(self::ADMIN.'/settings', ['notify_admin_email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('notify_admin_email');
        $this->putJson(self::ADMIN.'/settings', ['storefront_track_url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('storefront_track_url');
        $this->putJson(self::ADMIN.'/settings', ['storefront_track_url' => 'https://shop.test/track'])->assertOk();
        $tabs = collect($this->getJson(self::ADMIN.'/settings')->json('tabs'))->pluck('key')->all();
        $this->assertSame(['notifications'], $tabs);
    }

    public function test_the_developer_never_gets_order_data_through_mail_settings_or_status(): void
    {
        $this->place();
        $this->actingAsAdmin('developer');

        $settings = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertContains('notifications', collect($settings->json('tabs'))->pluck('key')->all());   // the developer configures mail...
        $this->assertStringNotContainsString('ORD-', $this->getJson(self::ADMIN.'/notifications/status')->getContent());   // ...but sees no order
        $this->assertContains(Mailable::class, class_implements(OrderPlacedMail::class));
    }
}

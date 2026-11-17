<?php

namespace Tests\Feature;

use App\Domain\Notification\QueueHealth;
use App\Mail\OrderPlacedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** No queue worker running (a plain host): the mails and pushes of an order are sent right after the response instead of waiting in the queue for ever. */
class WorkerFallbackTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('mail_enabled', true);
        $this->setSetting('delivery_fee', 0);
    }

    private function order(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $this->contact(['email' => 'buyer@example.test'])]))->assertCreated();
    }

    public function test_without_a_worker_the_mail_is_sent_by_the_request_and_nothing_waits_in_the_queue(): void
    {
        config(['queue.default' => 'database']);   // a real queue, and nobody takes jobs from it
        $this->assertTrue(QueueHealth::workerMissing());
        Mail::fake();

        $this->order();

        Mail::assertSent(OrderPlacedMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
        Mail::assertNotQueued(OrderPlacedMail::class);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_the_fallback_does_not_make_the_next_notification_wait_for_a_worker(): void
    {
        // the bug: a mail sent right after the response ran on the sync connection, its JobProcessed event stamped "a worker is running", and every
        // later mail (confirmed, shipped, delivered...) was queued for a worker that does not exist
        config(['queue.default' => 'database']);
        Mail::fake();

        $this->order();
        $this->order();
        $this->order();

        $this->assertTrue(QueueHealth::workerMissing(), 'sending after the response must not look like a worker');
        $this->assertNull(QueueHealth::status()['last_activity_at']);
        Mail::assertSent(OrderPlacedMail::class);
        Mail::assertNotQueued(OrderPlacedMail::class);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_dead_mail_server_without_a_worker_still_leaves_a_trace_in_the_failed_jobs(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        app('mail.manager')->forgetMailers();

        $this->order();   // the order is placed (assertCreated inside) whatever the mail server does

        $this->assertSame(1, DB::table('failed_jobs')->where('connection', 'after-response')->count());
        $row = DB::table('failed_jobs')->first();
        $this->assertSame(OrderPlacedMail::class, json_decode($row->payload, true)['displayName']);
        $this->assertStringNotContainsString('buyer@example.test', $row->payload.$row->exception, 'no address, no content');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_real_worker_still_leaves_its_stamp(): void
    {
        config(['queue.default' => 'database']);
        $this->assertNull(QueueHealth::status()['last_activity_at']);
        event(new \Illuminate\Queue\Events\JobProcessed('database', \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)));

        $this->assertNotNull(QueueHealth::status()['last_activity_at']);
        $this->assertFalse(QueueHealth::workerMissing());
    }

    public function test_with_a_worker_nothing_changes_and_the_mail_is_queued(): void
    {
        config(['queue.default' => 'database']);
        QueueHealth::recordActivity();   // a worker finished a job a moment ago
        $this->assertFalse(QueueHealth::workerMissing());
        Mail::fake();

        $this->order();

        Mail::assertQueued(OrderPlacedMail::class);
        Mail::assertNotSent(OrderPlacedMail::class);
    }

    public function test_the_fallback_can_be_switched_off(): void
    {
        config(['queue.default' => 'database', 'shop.jobs_without_worker' => false]);
        $this->assertFalse(QueueHealth::workerMissing());
        Mail::fake();

        $this->order();

        Mail::assertQueued(OrderPlacedMail::class);
    }
}

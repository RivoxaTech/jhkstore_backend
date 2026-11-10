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

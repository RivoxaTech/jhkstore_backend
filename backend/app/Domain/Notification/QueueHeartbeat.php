<?php

namespace App\Domain\Notification;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A job that does nothing but prove a queue worker is running (queued every minute by `shop:queue-heartbeat`). See QueueHealth. */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public function handle(): void
    {
        QueueHealth::recordHeartbeat();
    }
}

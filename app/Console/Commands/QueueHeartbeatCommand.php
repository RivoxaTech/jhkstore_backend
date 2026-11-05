<?php

namespace App\Console\Commands;

use App\Domain\Notification\QueueHeartbeat;
use App\Domain\Notification\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled every minute: queues one tiny job so the admin panel can tell whether a queue worker is running (Settings > Notifications).
 * It never piles up: while a heartbeat job still waits in the queue (no worker), no second one is added.
 */
class QueueHeartbeatCommand extends Command
{
    protected $signature = 'shop:queue-heartbeat';

    protected $description = 'Queue a heartbeat job so the panel can show whether the queue worker is running';

    public function handle(): int
    {
        if (config('queue.default') === 'sync') {
            QueueHealth::recordHeartbeat();   // no worker needed: the "queue" is this process

            return self::SUCCESS;
        }
        if (config('queue.default') === 'database' && DB::table('jobs')->where('payload', 'like', '%QueueHeartbeat%')->exists()) {
            $this->warn('A heartbeat job is still waiting: is the queue worker running?');

            return self::SUCCESS;
        }
        QueueHeartbeat::dispatch();
        $this->info('Heartbeat queued.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Notification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is a queue worker running? A worker cannot be seen from the web request, so the answer comes from three signs:
 *  - every job a worker finishes or fails stamps `queue.last_activity` (AppServiceProvider listens to the queue events),
 *  - the scheduled command `shop:queue-heartbeat` queues a tiny job every minute; a worker that runs it stamps `queue.heartbeat`,
 *  - a job that has waited longer than {@see STALE_SECONDS} in the `jobs` table means nobody is taking jobs.
 * States: `sync` (no queue: jobs run inside the request), `running` (activity within the last {@see ACTIVE_SECONDS}), `stopped` (a job has waited too
 * long and no worker was seen since), `idle` (nothing waiting, nothing seen lately: it cannot be told, send a test notification to find out).
 */
class QueueHealth
{
    public const STALE_SECONDS = 120;

    public const ACTIVE_SECONDS = 600;

    public const HEARTBEAT_FRESH_SECONDS = 180;

    public static function recordActivity(): void
    {
        self::put('queue.last_activity');
    }

    public static function recordHeartbeat(): void
    {
        self::put('queue.heartbeat');
        self::put('queue.last_activity');
    }

    /** @return array{state:string, connection:string, pending:int, oldest_pending_seconds:?int, last_activity_at:?string, heartbeat_at:?string} */
    public static function status(): array
    {
        $connection = (string) config('queue.default');
        $pending = 0;
        $oldest = null;
        if ($connection === 'database') {
            try {
                $pending = (int) DB::table('jobs')->count();
                $created = DB::table('jobs')->min('created_at');   // unix seconds
                $oldest = $created ? max(0, time() - (int) $created) : null;
            } catch (Throwable) {
                // no jobs table yet: nothing to report
            }
        }

        $activity = self::get('queue.last_activity');
        $heartbeat = self::get('queue.heartbeat');
        $recent = $activity !== null && time() - $activity <= self::ACTIVE_SECONDS;

        $state = match (true) {
            $connection === 'sync' => 'sync',
            $oldest !== null && $oldest > self::STALE_SECONDS && ! ($activity !== null && time() - $activity <= self::STALE_SECONDS) => 'stopped',
            $recent || ($heartbeat !== null && time() - $heartbeat <= self::HEARTBEAT_FRESH_SECONDS) => 'running',
            default => 'idle',
        };

        return [
            'state' => $state, 'connection' => $connection, 'pending' => $pending, 'oldest_pending_seconds' => $oldest,
            'last_activity_at' => $activity ? date('c', $activity) : null, 'heartbeat_at' => $heartbeat ? date('c', $heartbeat) : null,
        ];
    }

    private static function put(string $key): void
    {
        try {
            Cache::forever($key, time());
        } catch (Throwable) {
            // the cache is a convenience; a worker never fails because of it
        }
    }

    private static function get(string $key): ?int
    {
        try {
            $v = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
    }
}

<?php

namespace App\Domain\Notification;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What happened to the last push messages, for the "Notification status" card: the last real send (when, how many devices, how many answered
 * ok, the first error) and the result of "Send test notification" by test id. Stored in the cache (it is a status display, not a record);
 * never the body of a message, so no order number or total ends up here.
 */
class PushLog
{
    private const LAST = 'push.last';

    /** @param  array{title:string, devices:int, sent:int, failed:int, gone:int, error:?string}  $result */
    public static function record(array $result): void
    {
        self::put(self::LAST, $result + ['at' => date('c')], null);
    }

    public static function last(): ?array
    {
        return self::get(self::LAST);
    }

    /** A test belongs to the admin who asked: nobody else can read its result. */
    public static function recordTest(string $id, int $adminId, array $result): void
    {
        self::put('push.test.'.$id, $result + ['at' => date('c'), 'admin' => $adminId], 900);
    }

    public static function test(string $id): ?array
    {
        return self::get('push.test.'.$id);
    }

    /** A push service error can carry the subscription address: keep the sentence, drop the address. */
    public static function clean(?string $message): ?string
    {
        return $message === null || $message === '' ? null : mb_substr(trim((string) preg_replace('~https?://\S+~i', '[push service]', $message)), 0, 300);
    }

    private static function put(string $key, array $value, ?int $seconds): void
    {
        try {
            $seconds ? Cache::put($key, $value, $seconds) : Cache::forever($key, $value);
        } catch (Throwable) {
            // a status display must never fail a job
        }
    }

    private static function get(string $key): ?array
    {
        try {
            $v = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_array($v) ? $v : null;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * LOCAL development only: adds a `Server-Timing` header to every answer, so the browser's network panel (Timing tab) and the test scripts can tell
 * what a call cost on the server and how much of it was the database:
 *
 *   Server-Timing: total;dur=84.2, boot;dur=31.0, db;dur=9.8;desc="7 queries"
 *
 * `boot` is the time from the start of the request until this middleware ran (PHP start-up, the framework, the service providers); `total` ends when
 * the answer is ready. Never added outside `APP_ENV=local`.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $now = microtime(true);
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) ($request->server('REQUEST_TIME_FLOAT') ?: $now);
        $count = 0;
        $ms = 0.0;
        DB::listen(function ($query) use (&$count, &$ms) {
            $count++;
            $ms += $query->time;
        });

        $response = $next($request);

        $response->headers->set('Server-Timing', sprintf(
            'total;dur=%.1f, boot;dur=%.1f, db;dur=%.1f;desc="%d queries"',
            (microtime(true) - $start) * 1000,
            max(0, $now - $start) * 1000,
            $ms,
            $count,
        ));

        return $response;
    }
}

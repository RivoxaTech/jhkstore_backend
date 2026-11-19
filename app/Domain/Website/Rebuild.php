<?php

namespace App\Domain\Website;

use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Settings;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The rebuild of the static storefront (a Cloudflare Pages style deploy hook: an empty POST starts a build).
 *
 * `changed()` is called when something customers can see was changed in the admin panel (see TrackStorefrontChanges). It counts the change and
 * schedules ONE queued job `TriggerRebuild` for the end of the debounce window: edits made inside the window only raise the counter.
 * `ping()` is the one place that calls the hook (10 second timeout); it records the outcome (and a short error, never the hook address or the secret)
 * and is used by the job, the "Rebuild website now" button and `php artisan shop:rebuild`.
 *
 * State (pending changes, the window, last request and result) is kept in the cache: it is only a status, nothing depends on it for correctness.
 * Nothing in here may ever break the admin action that caused it: `changed()` swallows every error.
 */
class Rebuild
{
    public const PENDING = 'website.rebuild.pending';

    public const SCHEDULED = 'website.rebuild.scheduled';

    public const LAST_REQUESTED = 'website.rebuild.last_requested_at';

    public const LAST_RESULT = 'website.rebuild.last_result';

    public function __construct(private Settings $settings) {}

    /** Is a hook address set? */
    public function configured(): bool
    {
        return str_starts_with((string) $this->settings->get('rebuild_hook_url'), 'https://');
    }

    /** Automatic rebuilds: switched on and a hook address is set. */
    public function automatic(): bool
    {
        return (bool) $this->settings->get('rebuild_enabled') && $this->configured();
    }

    /** Something visible on the storefront changed. Counts it and makes sure one rebuild job is waiting. Never throws. */
    public function changed(): void
    {
        try {
            if (! $this->automatic()) {
                return;
            }
            Cache::forever(self::PENDING, (int) Cache::get(self::PENDING, 0) + 1);

            $wait = $this->debounce();
            // the first change of a window schedules the job; the others find the window open and only raise the counter
            if (Cache::add(self::SCHEDULED, now()->getTimestamp(), $wait + 600)) {
                TriggerRebuild::dispatch()->delay(now()->addSeconds($wait));
            }
        } catch (\Throwable $e) {
            Log::warning('Rebuild: a storefront change could not be recorded: '.$e->getMessage());
        }
    }

    public function debounce(): int
    {
        return max(10, (int) $this->settings->get('rebuild_debounce_seconds'));
    }

    public function pending(): int
    {
        return (int) Cache::get(self::PENDING, 0);
    }

    /** The job is about to run: the window is over (the next change opens a new one). */
    public function closeWindow(): int
    {
        Cache::forget(self::SCHEDULED);

        return $this->pending();
    }

    /**
     * Calls the hook now. Records when it was requested and what came back. `$handled` is the number of pending changes this call covers.
     *
     * @return array{ok: bool, status: ?int, error: ?string, at: string}
     */
    public function ping(int $handled = 0): array
    {
        $url = (string) $this->settings->get('rebuild_hook_url');
        $secret = (string) $this->settings->get('rebuild_hook_secret');
        $at = now()->toIso8601String();
        Cache::forever(self::LAST_REQUESTED, $at);

        if (! str_starts_with($url, 'https://')) {
            return $this->record(false, null, 'No rebuild hook address is set (it must start with https://).', $at);
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withHeaders($secret !== '' ? ['X-Rebuild-Secret' => $secret] : [])
                ->withBody('', 'text/plain')->post($url);
        } catch (\Throwable $e) {
            return $this->record(false, null, $this->clean($e->getMessage(), $url, $secret), $at);
        }

        if (! $response->successful()) {
            return $this->record(false, $response->status(), 'The hosting answered HTTP '.$response->status().'.', $at);
        }
        Cache::forever(self::PENDING, max(0, $this->pending() - $handled));

        return $this->record(true, $response->status(), null, $at);
    }

    /** "Rebuild website now" and the console command: the same call, at once, audited. */
    public function now(?Admin $admin, string $via = 'panel'): array
    {
        $result = $this->ping($this->pending());
        Audit::log($admin, 'website.rebuild_requested', null, ['via' => $via, 'ok' => $result['ok'], 'error' => $result['error']]);

        return $result;
    }

    /** The last outcome (a failed final attempt of the job is recorded by `failed()`). */
    public function record(bool $ok, ?int $status, ?string $error, ?string $at = null): array
    {
        $result = ['ok' => $ok, 'status' => $status, 'error' => $error, 'at' => $at ?? now()->toIso8601String()];
        Cache::forever(self::LAST_RESULT, $result);

        return $result;
    }

    /**
     * Which version of the website is online. Every build of the storefront writes `/build.json` ({"built_at": "..."}, the time the build started);
     * the server reads it from the website's address (setting `website_url`). A rebuild asked for after that time is still on its way.
     *
     * @return array{state: string, built_at: ?string}  state: no_address | unreachable | live | building | stuck
     */
    public function live(?string $requestedAt): array
    {
        $base = rtrim((string) $this->settings->get('website_url'), '/');
        if (! str_starts_with($base, 'https://')) {
            return ['state' => 'no_address', 'built_at' => null];
        }

        $builtAt = Cache::remember('website.live.built_at.'.md5($base), 10, function () use ($base) {
            try {
                $response = Http::timeout(4)->connectTimeout(3)->acceptJson()->get($base.'/build.json', ['t' => time()]);
                $at = $response->successful() ? $response->json('built_at') : null;
            } catch (\Throwable) {
                return null;
            }

            return is_string($at) && strtotime($at) !== false ? $at : null;
        });
        if ($builtAt === null) {
            return ['state' => 'unreachable', 'built_at' => null];
        }

        $requested = $requestedAt ? strtotime($requestedAt) : false;
        $state = match (true) {
            $requested === false || strtotime($builtAt) >= $requested => 'live',
            time() - $requested <= 15 * 60 => 'building',
            default => 'stuck',
        };

        return ['state' => $state, 'built_at' => date('c', strtotime($builtAt))];
    }

    /** What the Website tab shows. Never the address or the secret: only whether they are set. */
    public function status(): array
    {
        $last = Cache::get(self::LAST_RESULT);

        return [
            'enabled' => (bool) $this->settings->get('rebuild_enabled'),
            'hook_configured' => $this->configured(),
            'secret_set' => $this->settings->isSet('rebuild_hook_secret'),
            'debounce_seconds' => $this->debounce(),
            'pending_changes' => $this->pending(),
            'waiting' => Cache::has(self::SCHEDULED),
            'last_requested_at' => Cache::get(self::LAST_REQUESTED),
            'live' => $this->live(Cache::get(self::LAST_REQUESTED)),
            'last_result' => $last ? ['ok' => (bool) $last['ok'], 'error' => $last['error'], 'at' => $last['at']] : null,
            'warning' => $last && ! $last['ok'],
        ];
    }

    /** A short, single-line error that cannot leak the address (it carries a token) or the secret. */
    private function clean(string $message, string $url, string $secret): string
    {
        $message = str_replace(array_filter([$url, $secret, parse_url($url, PHP_URL_HOST) ?: null]), '[hook]', $message);
        $message = preg_replace('/\s*\(see https?:\/\/\S+\)/', '', $message);   // cURL's "(see https://curl.se/...)" pointer
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($message !== '' ? $message : 'The hook could not be reached.', 0, 160);
    }
}

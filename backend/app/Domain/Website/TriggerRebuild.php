<?php

namespace App\Domain\Website;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The debounced rebuild: one job per window of changes (see Rebuild::changed). It calls the hook with a 10 second timeout, 3 attempts with a growing
 * pause. It only reports to the status of the Website tab: it never touches the admin action that caused it.
 */
class TriggerRebuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    /** @return list<int> seconds to wait before the 2nd and 3rd attempt */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Rebuild $rebuild): void
    {
        // the first attempt closes the window (a change made from now on opens a new one); a retry keeps what is already counted
        $handled = $this->attempts() === 1 ? $rebuild->closeWindow() : $rebuild->pending();
        if ($handled === 0 && $this->attempts() === 1) {
            return;   // a manual rebuild already covered every change
        }

        $result = $rebuild->ping($handled);
        if (! $result['ok']) {
            throw new \RuntimeException($result['error'] ?? 'The rebuild hook failed.');   // the queue retries after the backoff
        }
    }

    public function failed(\Throwable $e): void
    {
        $result = app(Rebuild::class)->status()['last_result'];
        if (! $result || $result['ok']) {
            app(Rebuild::class)->record(false, null, mb_substr($e->getMessage(), 0, 160));
        }
    }
}

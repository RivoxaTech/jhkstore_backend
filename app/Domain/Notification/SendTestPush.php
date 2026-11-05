<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Send test notification": a real push to the device(s) of ONE admin (the one who pressed the button; `$endpoint` narrows it to the browser
 * they are using), on the real queue like every other notification. The outcome is written to PushLog under the test id, which the panel polls.
 * It is not an order notification: it carries no order data and goes only to the admin who asked.
 */
class SendTestPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public function __construct(public int $adminId, public string $testId, public ?string $endpoint = null) {}

    public function handle(PushSender $sender, Settings $settings): void
    {
        $subscriptions = PushSubscription::query()->where('admin_id', $this->adminId)
            ->when($this->endpoint, fn ($q, $e) => $q->where('endpoint', $e))->get();
        if ($subscriptions->isEmpty()) {
            PushLog::recordTest($this->testId, $this->adminId, ['status' => 'no_device', 'devices' => 0, 'sent' => 0, 'error' => 'No device of yours is subscribed: press the bell and allow notifications first.']);

            return;
        }

        $payload = json_encode(PushMessage::test($settings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sent = 0;
        $error = null;
        foreach ($subscriptions as $subscription) {
            try {
                $result = $sender->send(['endpoint' => $subscription->endpoint, 'p256dh' => $subscription->p256dh, 'auth' => $subscription->auth], $payload);
                if ($result !== 'sent') {
                    $error ??= $result === 'gone'
                        ? 'The push service says this device is no longer subscribed. Turn the bell off and on again.'
                        : ($sender instanceof WebPushSender && $sender->lastError ? PushLog::clean($sender->lastError) : 'The push service did not accept the message.');
                }
            } catch (\Throwable $e) {
                $result = 'failed';
                $error ??= PushLog::clean($e->getMessage()) ?? 'The push could not be sent.';
            }
            if ($result === 'gone') {
                $subscription->delete();
            } elseif ($result === 'sent') {
                $sent++;
                $subscription->forceFill(['last_used_at' => now()])->save();
            }
        }

        $ok = $sent === $subscriptions->count();
        PushLog::recordTest($this->testId, $this->adminId, ['status' => $ok ? 'sent' : ($sent > 0 ? 'partial' : 'failed'), 'devices' => $subscriptions->count(), 'sent' => $sent, 'error' => $ok ? null : $error]);
        PushLog::record(['title' => 'Test notification', 'devices' => $subscriptions->count(), 'sent' => $sent, 'failed' => $subscriptions->count() - $sent, 'gone' => 0, 'error' => $ok ? null : $error]);
    }

    /** The job failed before it could report (a crash, an exception outside the loop): the panel must not wait forever. */
    public function failed(\Throwable $e): void
    {
        PushLog::recordTest($this->testId, $this->adminId, ['status' => 'failed', 'devices' => 0, 'sent' => 0, 'error' => PushLog::clean($e->getMessage()) ?? 'The test job failed.']);
    }
}

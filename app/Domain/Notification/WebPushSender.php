<?php

namespace App\Domain\Notification;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** The real sender: minishlink/web-push with the VAPID keys of config/webpush.php. */
class WebPushSender implements PushSender
{
    /** Why the last send failed (the push service's own words), for the test notification. */
    public ?string $lastError = null;

    public static function configured(): bool
    {
        $v = config('webpush.vapid');

        return filled($v['public_key']) && filled($v['private_key']) && filled($v['subject']);
    }

    public function send(array $subscription, string $payload): string
    {
        $this->lastError = null;
        $vapid = config('webpush.vapid');
        $push = new WebPush(['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]], ['TTL' => 3600], 10);
        $report = $push->sendOneNotification(Subscription::create([
            'endpoint' => $subscription['endpoint'], 'keys' => ['p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
        ]), $payload);

        if ($report->isSuccess()) {
            return 'sent';
        }
        if ($report->isSubscriptionExpired() || in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
            return 'gone';
        }
        $this->lastError = $report->getReason();
        Log::warning('Web push failed: '.$report->getReason());

        return 'failed';
    }
}

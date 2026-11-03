<?php

namespace App\Domain\Notification;

/** Sends one Web Push message. The real one is WebPushSender; the tests bind a recording fake. */
interface PushSender
{
    /**
     * @param  array{endpoint:string, p256dh:string, auth:string}  $subscription
     * @return 'sent'|'gone'|'failed'  `gone` = the push service says the subscription no longer exists (404 / 410): delete it
     */
    public function send(array $subscription, string $payload): string;
}

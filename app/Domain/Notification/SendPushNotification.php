<?php

namespace App\Domain\Notification;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Notification\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Sends one push notification to every subscribed browser of every admin who holds the permission (checked when the job runs, so an
 * admin whose access was switched off in the meantime gets nothing). The developer account therefore never receives an order notification:
 * that role can never hold `orders.view`. A 404 / 410 answer deletes the subscription. Runs on the queue after the order's transaction commits.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    /** @param  array{title:string, body:string, url:string, tag?:string}  $message */
    public function __construct(public string $permission, public array $message)
    {
        $this->afterCommit();
    }

    public function handle(PushSender $sender): void
    {
        $payload = json_encode($this->message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $allowed = self::recipients($this->permission)->pluck('id')->all();
        $subscriptions = PushSubscription::query()->whereIn('admin_id', $allowed)->get();

        foreach ($subscriptions as $subscription) {
            try {
                $result = $sender->send(['endpoint' => $subscription->endpoint, 'p256dh' => $subscription->p256dh, 'auth' => $subscription->auth], $payload);
            } catch (\Throwable $e) {
                Log::warning('Web push error: '.$e->getMessage());
                $result = 'failed';
            }
            if ($result === 'gone') {
                $subscription->delete();
            } elseif ($result === 'sent') {
                $subscription->forceFill(['last_used_at' => now()])->save();
            }
        }
    }

    /** The admins with a subscription who hold the permission right now (the hard rules of AdminAccess apply: never the developer for orders). */
    public static function recipients(string $permission)
    {
        return Admin::query()->whereIn('id', PushSubscription::query()->select('admin_id'))->where('is_active', true)
            ->with(['roles.permissions', 'permissions'])->get()
            ->filter(fn (Admin $a) => AdminAccess::allows($a, $permission))->values();
    }
}

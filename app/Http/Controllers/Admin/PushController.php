<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\WebPushSender;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell button of the admin panel: this browser subscribes or unsubscribes. A subscription belongs to the logged-in admin; what the admin
 * is told about is decided when a notification is sent (permission `orders.view` for orders and payment proofs, `wholesale.view` for
 * wholesale requests), see SendPushNotification.
 */
class PushController extends Controller
{
    /** GET push/status[?endpoint=]: is push switched on and configured, the public key, and whether THIS browser is subscribed. */
    public function status(Request $request, Settings $settings): JsonResponse
    {
        $mine = PushSubscription::query()->where('admin_id', $request->user()->id);

        return response()->json([
            'enabled' => (bool) $settings->get('push_enabled'),            // the switch in Settings > Notifications
            'configured' => WebPushSender::configured(),                    // the VAPID keys are in .env
            'public_key' => WebPushSender::configured() ? (string) config('webpush.vapid.public_key') : null,
            'subscribed' => $request->filled('endpoint') ? (clone $mine)->where('endpoint', $request->query('endpoint'))->exists() : null,
            'devices' => (clone $mine)->count(),
        ]);
    }

    /** POST push/subscribe: the browser's PushSubscription ({ endpoint, keys: { p256dh, auth } }). Subscribing again moves it to the current admin. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);

        PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'admin_id' => $request->user()->id, 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'last_used_at' => now(),
        ]);

        return response()->json(['subscribed' => true], 201);
    }

    /** POST push/unsubscribe { endpoint }: only the admin's own browsers can be removed. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        PushSubscription::query()->where('admin_id', $request->user()->id)->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['subscribed' => false]);
    }
}

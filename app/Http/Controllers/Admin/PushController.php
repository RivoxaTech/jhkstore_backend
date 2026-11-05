<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\PushLog;
use App\Domain\Notification\QueueHealth;
use App\Domain\Notification\SendTestPush;
use App\Domain\Notification\WebPushSender;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'queue' => QueueHealth::status()['state'],                      // running | stopped | idle | sync
            'last' => PushLog::last(),                                       // the last push that was sent and how it went
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

    /**
     * POST push/test { endpoint? }: queues a real test push to this admin's own device(s) (just the browser with `endpoint`, if given) and returns
     * a test id to poll with GET push/test/{id}. Needs the VAPID keys; it does not need the switch in Settings (so a new install can be tested first).
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['nullable', 'string', 'max:500']]);
        if (! WebPushSender::configured()) {
            return response()->json(['message' => 'Push is not set up on the server: the VAPID keys are missing in .env (php artisan shop:vapid).', 'code' => 'vapid_missing'], 422);
        }
        $id = Str::lower(Str::random(16));
        PushLog::recordTest($id, $request->user()->id, ['status' => 'queued', 'devices' => 0, 'sent' => 0, 'error' => null]);
        SendTestPush::dispatch($request->user()->id, $id, $data['endpoint'] ?? null);

        return response()->json(['id' => $id, 'queue' => QueueHealth::status()['state']], 202);
    }

    /** GET push/test/{id}: queued | sent | partial | failed | no_device, with the push service's own error text when it failed. */
    public function testResult(Request $request, string $id): JsonResponse
    {
        $result = PushLog::test($id);
        abort_if($result === null || ($result['admin'] ?? null) !== $request->user()->id, 404, 'Unknown or expired test.');
        unset($result['admin']);

        return response()->json($result + ['queue' => QueueHealth::status()['state']]);
    }

    /** POST push/unsubscribe { endpoint }: only the admin's own browsers can be removed. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        PushSubscription::query()->where('admin_id', $request->user()->id)->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['subscribed' => false]);
    }
}

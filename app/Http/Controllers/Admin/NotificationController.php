<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\WebPushSender;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** The Notifications tab: how the queue and the mail setup are doing, and a test e-mail (permission `settings.notifications`). */
class NotificationController extends Controller
{
    /** Counts only (never the content of a failed job, which could hold an order). */
    public function status(Settings $settings): JsonResponse
    {
        return response()->json([
            'queue_connection' => (string) config('queue.default'),
            'mailer' => (string) config('mail.default'),
            'from_address' => (string) config('mail.from.address'),
            'pending_jobs' => (int) DB::table('jobs')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'mail_enabled' => (bool) $settings->get('mail_enabled'),
            'push' => [
                'enabled' => (bool) $settings->get('push_enabled'),
                'configured' => WebPushSender::configured(),
                'devices' => PushSubscription::query()->count(),
            ],
        ]);
    }

    /** POST notifications/test-email { to? }: sent at once (not queued), so a mistake in the mail setup shows up as an error here. */
    public function testEmail(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'email:rfc', 'max:190']]);
        $to = trim((string) ($data['to'] ?? '')) ?: trim((string) $settings->get('notify_admin_email')) ?: trim((string) $request->user()->email);
        if ($to === '') {
            return response()->json(['message' => 'Enter an e-mail address to send the test to.', 'errors' => ['to' => ['Enter an e-mail address to send the test to.']]], 422);
        }

        try {
            Mail::to($to)->send(new TestMail);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The test e-mail could not be sent: '.$e->getMessage(), 'code' => 'mail_failed'], 502);
        }
        Audit::log($request->user(), 'notifications.test_email', null, ['to' => $to]);

        return response()->json(['message' => "Test e-mail sent to $to.", 'to' => $to]);
    }
}

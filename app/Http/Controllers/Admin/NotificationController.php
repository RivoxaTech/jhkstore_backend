<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Notification\MailServer;
use App\Domain\Notification\Models\PushSubscription;
use App\Domain\Notification\PushLog;
use App\Domain\Notification\QueueHealth;
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
    /**
     * Everyone who may open the tab gets: whether a queue worker is running, the number of failed jobs, the mail switch and the push summary
     * (switch, keys, devices, the last push and its result). The technical details (queue connection, mailer, sender address, jobs waiting, how long
     * the oldest has waited) need `settings.advanced`. Counts and names only, never the content of a job (it could hold an order).
     */
    public function status(Request $request, Settings $settings): JsonResponse
    {
        $queue = QueueHealth::status();
        app(MailServer::class)->apply();   // so the technical details below say what is really used
        $out = [
            'queue' => ['state' => $queue['state'], 'last_activity_at' => $queue['last_activity_at']],
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'mail_enabled' => (bool) $settings->get('mail_enabled'),
            'push' => [
                'enabled' => (bool) $settings->get('push_enabled'),
                'configured' => WebPushSender::configured(),
                'devices' => PushSubscription::query()->count(),
                'last' => PushLog::last(),
            ],
        ];
        if (AdminAccess::allows($request->user(), AdminAccess::SETTINGS_ADVANCED)) {
            $out += [
                'queue_connection' => $queue['connection'],
                'mailer' => (string) config('mail.default'),
                'mail_account' => app(MailServer::class)->custom() ? 'panel' : 'env',   // Settings > E-mail, or the MAIL_* values of the server
                'from_address' => (string) config('mail.from.address'),
                'pending_jobs' => $queue['pending'],
            ];
            $out['queue'] += ['pending' => $queue['pending'], 'oldest_pending_seconds' => $queue['oldest_pending_seconds'], 'heartbeat_at' => $queue['heartbeat_at']];
        }

        return response()->json($out);
    }

    /** GET notifications/failed-jobs: what failed and when (job name, queue, time, exception class). Never the content or the message. `settings.advanced`. */
    public function failedJobs(): JsonResponse
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get(['id', 'queue', 'payload', 'exception', 'failed_at'])->map(fn ($r) => [
            'id' => $r->id,
            'job' => class_basename((string) (json_decode($r->payload, true)['displayName'] ?? 'job')),
            'queue' => $r->queue,
            'exception' => strtok((string) $r->exception, ':') ?: null,
            'failed_at' => $r->failed_at,
        ]);

        return response()->json(['total' => (int) DB::table('failed_jobs')->count(), 'items' => $rows->values()]);
    }
    /** POST notifications/test-email { to? }: sent at once (not queued), so a mistake in the mail setup shows up as an error here. */
    public function testEmail(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'email:rfc', 'max:190']]);
        $to = trim((string) ($data['to'] ?? '')) ?: trim((string) $settings->get('notify_admin_email')) ?: trim((string) $request->user()->email);
        if ($to === '') {
            return response()->json(['message' => 'Enter an e-mail address to send the test to.', 'errors' => ['to' => ['Enter an e-mail address to send the test to.']]], 422);
        }

        app(MailServer::class)->apply();   // the account of Settings > E-mail, as saved
        try {
            Mail::to($to)->send(new TestMail);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The test e-mail could not be sent: '.$e->getMessage(), 'code' => 'mail_failed'], 502);
        }
        Audit::log($request->user(), 'notifications.test_email', null, ['to' => $to]);

        return response()->json(['message' => "Test e-mail sent to $to.", 'to' => $to]);
    }
}

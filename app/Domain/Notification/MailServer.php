<?php

namespace App\Domain\Notification;

use App\Domain\Support\Settings;
use Throwable;

/**
 * The mail account of Settings > E-mail. When "Send with this mail account" is on (and a mail server is given), every e-mail goes out through it;
 * otherwise the MAIL_* values of the server (.env) are used, exactly as before. The values are put into Laravel's mail configuration just before mail
 * is sent: by the request that sends it, by the queue worker before every job (AppServiceProvider), and by the test e-mail. A worker that runs for days
 * therefore follows a change made in the panel without a restart.
 */
class MailServer
{
    /** The configuration the server started with (.env), kept so that switching the account off in the panel gives it back. */
    private static ?array $original = null;

    /** Fingerprint of what was put in last, so the mailer is rebuilt only when something changed. */
    private static ?string $applied = null;

    public function __construct(private readonly Settings $settings) {}

    /** Is the mail account of the panel in use? (switched on and a server given) */
    public function custom(): bool
    {
        return (bool) $this->settings->get('mail_custom') && trim((string) $this->settings->get('mail_host')) !== '';
    }

    public function apply(): void
    {
        try {
            $this->configure();
        } catch (Throwable) {
            // the settings cannot be read (no database yet): the server's own configuration stays
        }
    }

    private function configure(): void
    {
        self::$original ??= [
            'default' => config('mail.default'),
            'smtp' => config('mail.mailers.smtp'),
            'from' => config('mail.from.address'),
        ];

        if (! $this->custom()) {
            $this->put(self::$original['default'], self::$original['smtp'], self::$original['from'], 'env');

            return;
        }

        $encryption = (string) $this->settings->get('mail_encryption');
        $smtp = [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => trim((string) $this->settings->get('mail_host')),
            'port' => (int) $this->settings->get('mail_port') ?: 587,
            'username' => trim((string) $this->settings->get('mail_username')) ?: null,
            'password' => (string) $this->settings->get('mail_password') ?: null,
            'timeout' => 20,
            'local_domain' => self::$original['smtp']['local_domain'] ?? null,
        ];
        if ($encryption === 'none') {
            $smtp['auto_tls'] = false;
        }
        $from = trim((string) $this->settings->get('mail_from_address')) ?: self::$original['from'];

        $this->put('smtp', $smtp, $from, 'panel');
    }

    private function put(?string $default, ?array $smtp, ?string $from, string $source): void
    {
        $print = md5(json_encode([$default, $smtp, $from, $source]));
        if ($print === self::$applied) {
            return;
        }
        config(['mail.default' => $default, 'mail.mailers.smtp' => $smtp, 'mail.from.address' => $from]);
        $manager = app('mail.manager');   // (not the facade: a test may have faked it)
        foreach (array_unique(array_filter(['smtp', $default, self::$original['default'] ?? null])) as $name) {
            $manager->purge($name);   // a mailer built with the old values is thrown away; the next mail builds a new one
        }
        self::$applied = $print;
    }

    /** For the tests: forget what was applied. */
    public static function forget(): void
    {
        self::$original = null;
        self::$applied = null;
    }
}

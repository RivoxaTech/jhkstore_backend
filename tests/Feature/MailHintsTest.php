<?php

namespace Tests\Feature;

use App\Domain\Notification\MailHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The test e-mail of Settings explains itself: the log mailer is not "sent", and the usual SMTP failures come with one sentence of advice. */
class MailHintsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    public function test_the_usual_failures_get_a_plain_sentence_of_advice(): void
    {
        $this->assertStringContainsString('App Password', MailHints::for('Failed to authenticate on SMTP server with username "x@gmail.com" using the available authenticators (535 5.7.8 Username and Password not accepted)'));
        $this->assertStringContainsString('hosting itself', MailHints::for('Connection could not be established with host "smtp.gmail.com:465": stream_socket_client(): Unable to connect (Connection timed out)'));
        $this->assertStringContainsString('port 465 needs encryption ssl', strtolower(MailHints::for('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed, certificate verify failed')));
        $this->assertStringContainsString('same address as the user name', MailHints::for('Expected response code "250" but got code "553", with message "553 sender not allowed"'));
        $this->assertNull(MailHints::for('something nobody has seen before'));
        $this->assertStringNotContainsString('password', strtolower(MailHints::for('Connection refused') ?? ''), 'no secrets are ever in the advice');
    }

    public function test_the_log_mailer_is_not_reported_as_sent(): void
    {
        $this->actingAsAdmin('developer');
        config(['mail.default' => 'log']);

        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'mail_not_configured')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'only writes e-mails into a log file') && str_contains($m, 'Settings, E-mail'));
    }

    public function test_a_failed_send_carries_the_error_and_the_advice(): void
    {
        $this->actingAsAdmin('developer');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.scheme' => 'smtp']);
        app('mail.manager')->forgetMailers();

        $res = $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertStatus(502)->assertJsonPath('code', 'mail_failed');
        $this->assertStringStartsWith('The test e-mail could not be sent:', $res->json('message'));
        $this->assertNotEmpty($res->json('hint'));
    }
}

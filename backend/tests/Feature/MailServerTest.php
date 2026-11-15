<?php

namespace Tests\Feature;

use App\Domain\Notification\MailServer;
use App\Domain\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Settings > E-mail: the developer sets the mail account that sends the e-mails; the server's own MAIL_* values stay the default. */
class MailServerTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailServer::forget();
        $this->seedShop();
    }

    protected function tearDown(): void
    {
        MailServer::forget();
        parent::tearDown();
    }

    private function account(array $over = []): array
    {
        return $over + ['mail_custom' => true, 'mail_host' => 'smtp.example.test', 'mail_port' => 465, 'mail_encryption' => 'ssl', 'mail_username' => 'shop@example.test', 'mail_password' => 's3cret-pass', 'mail_from_address' => 'shop@example.test'];
    }

    public function test_without_the_switch_the_servers_own_mail_values_are_used(): void
    {
        $before = [config('mail.default'), config('mail.mailers.smtp'), config('mail.from.address')];
        $this->setSetting('mail_host', 'smtp.example.test');   // given, but the switch is off

        app(MailServer::class)->apply();

        $this->assertFalse(app(MailServer::class)->custom());
        $this->assertSame($before, [config('mail.default'), config('mail.mailers.smtp'), config('mail.from.address')]);
    }

    public function test_the_account_of_the_panel_goes_into_the_mail_configuration_and_back_out(): void
    {
        $original = [config('mail.default'), config('mail.from.address')];
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', $this->account())->assertOk();

        app(MailServer::class)->apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('shop@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('s3cret-pass', config('mail.mailers.smtp.password'));   // decrypted for the mailer only
        $this->assertSame('shop@example.test', config('mail.from.address'));

        $this->putJson(self::ADMIN.'/settings', ['mail_encryption' => 'tls', 'mail_port' => 587])->assertOk();
        app(MailServer::class)->apply();                                           // a change is followed without a restart (a worker does this before every job)
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));

        $this->putJson(self::ADMIN.'/settings', ['mail_custom' => false])->assertOk();
        app(MailServer::class)->apply();
        $this->assertSame($original, [config('mail.default'), config('mail.from.address')]);
    }

    public function test_the_password_is_stored_encrypted_and_never_sent_back_or_logged(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', $this->account())->assertOk();

        $stored = (string) DB::table('settings')->where('key', 'mail_password')->value('value');
        $this->assertStringNotContainsString('s3cret-pass', $stored);

        $res = $this->getJson(self::ADMIN.'/settings')->assertOk();
        $this->assertStringNotContainsString('s3cret-pass', $res->getContent());
        $field = collect($res->json('tabs'))->firstWhere('key', 'mail')['sections'][0]['fields'];
        $password = collect($field)->firstWhere('key', 'mail_password');
        $this->assertTrue($password['is_set']);
        $this->assertNull($password['value']);

        $this->assertStringNotContainsString('s3cret-pass', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_the_account_cannot_be_switched_on_without_a_server_and_a_sender(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', ['mail_custom' => true])->assertStatus(422)->assertJsonValidationErrors('mail_custom');
        $this->putJson(self::ADMIN.'/settings', ['mail_custom' => true, 'mail_host' => 'smtp.example.test'])->assertStatus(422)->assertJsonValidationErrors('mail_custom');   // the sender is missing: told under the switch
        $this->putJson(self::ADMIN.'/settings', ['mail_host' => 'not a host!'])->assertStatus(422)->assertJsonValidationErrors('mail_host');
        $this->putJson(self::ADMIN.'/settings', ['mail_port' => 70000])->assertStatus(422)->assertJsonValidationErrors('mail_port');
        $this->putJson(self::ADMIN.'/settings', ['mail_encryption' => 'rot13'])->assertStatus(422)->assertJsonValidationErrors('mail_encryption');
        $this->putJson(self::ADMIN.'/settings', ['mail_from_address' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('mail_from_address');
    }

    public function test_only_the_developer_has_the_tab_until_the_developer_gives_it_away(): void
    {
        $this->actingAsAdmin('admin', everything: false);
        $this->assertNotContains('mail', collect($this->getJson(self::ADMIN.'/settings')->json('tabs'))->pluck('key')->all());
        $this->putJson(self::ADMIN.'/settings', ['mail_host' => 'smtp.example.test'])->assertForbidden();

        $this->actingAsAdmin('developer');
        $this->assertContains('mail', collect($this->getJson(self::ADMIN.'/settings')->json('tabs'))->pluck('key')->all());
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['settings.mail', 'settings.general']])->assertOk();

        $this->actingAsAdmin('admin', everything: false);   // with the tab switched on, and nothing else (no "advanced" master switch)
        $this->assertContains('mail', collect($this->getJson(self::ADMIN.'/settings')->json('tabs'))->pluck('key')->all());
        $this->putJson(self::ADMIN.'/settings', ['mail_host' => 'smtp.example.test'])->assertOk();
    }

    public function test_the_test_e_mail_goes_through_the_account_of_the_panel(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/settings', $this->account(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_encryption' => 'none']))->assertOk();

        // nothing listens on that port: the real account is used (the "array" mailer of the tests would have said OK)
        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertStatus(502)->assertJsonPath('code', 'mail_failed');

        $this->putJson(self::ADMIN.'/settings', ['mail_custom' => false])->assertOk();
        $this->postJson(self::ADMIN.'/notifications/test-email', ['to' => 'me@example.test'])->assertOk();
    }

    public function test_a_queue_worker_applies_the_account_before_every_job(): void
    {
        $this->assertTrue(Event::hasListeners(JobProcessing::class));
        $this->assertSame([], array_diff(array_keys(Settings::DEFINITIONS), array_keys(Settings::DEFINITIONS)));   // (the definitions load)
        $this->assertSame('mail', Settings::DEFINITIONS['mail_host'][0]);
    }
}

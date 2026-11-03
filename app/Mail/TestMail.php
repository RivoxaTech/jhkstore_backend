<?php

namespace App\Mail;

use App\Domain\Support\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The "send test e-mail" button of the Notifications tab. Sent at once (not queued), so a wrong mail setup is reported to the admin. */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        $settings = app(Settings::class);
        $name = trim((string) $settings->get('mail_from_name')) ?: (string) $settings->get('store_name');

        return new Envelope(from: new Address((string) config('mail.from.address'), $name), subject: 'Test e-mail from '.$settings->get('store_name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test', with: ['store' => (string) app(Settings::class)->get('store_name')]);
    }
}

<?php

namespace App\Domain\Notification;

/**
 * Turns the technical error of a failed mail send into one plain sentence of advice for the person who is setting up the mail account.
 * Only matches on the words of the error, never stores or returns a password or an address.
 */
class MailHints
{
    public static function for(string $error): ?string
    {
        $e = strtolower($error);

        return match (true) {
            str_contains($e, 'failed to authenticate') || str_contains($e, '535') || str_contains($e, 'username and password not accepted') || str_contains($e, 'authentication')
                => 'The mail server refused the user name or the password. Use the full e-mail address as the user name. Gmail does not accept the normal password: create an App Password (Google account, Security, 2-Step Verification, App passwords) and use that.',
            str_contains($e, 'connection could not be established') || str_contains($e, 'timed out') || str_contains($e, 'connection refused') || str_contains($e, 'network is unreachable') || str_contains($e, 'could not connect')
                => 'This server cannot reach that mail server on that port. Some hosts block port 465: try port 587 with encryption TLS first. If that fails as well, the host blocks outgoing mail to other providers (Gmail, Outlook); then use the mail account of the hosting itself: create one in cPanel (Email Accounts), then use mail.yourdomain, port 465, encryption SSL, the full address as user name.',
            str_contains($e, 'certificate') || str_contains($e, 'ssl') || str_contains($e, 'tls') || str_contains($e, 'crypto')
                => 'The secure connection failed. Port 465 needs encryption SSL; port 587 needs TLS (STARTTLS). Match the port and the encryption, and use the server name that is on the certificate (the host name of your mail provider).',
            str_contains($e, '553') || str_contains($e, 'sender') || str_contains($e, 'not allowed to send') || str_contains($e, 'from address')
                => 'The mail server does not allow this sender address. Set "Sender address" to the same address as the user name (or to an address of the same mail account).',
            str_contains($e, '550') || str_contains($e, 'rejected') || str_contains($e, 'spam') || str_contains($e, 'blocked')
                => 'The receiving mail server rejected the message. Check the address, and that SPF and DKIM are set for your domain (they are in the DNS of the domain).',
            default => null,
        };
    }
}

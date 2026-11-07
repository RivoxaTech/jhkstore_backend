<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'shop:vapid';

    protected $description = 'Print a new VAPID key pair for the admin push notifications (copy the lines into .env).';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_SUBJECT=mailto:you@example.com');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Add these three lines to .env, set VAPID_SUBJECT to a contact address (mailto: or https), keep the private key secret, then switch "Admin push notifications" on in Settings > Notifications.');
        $this->comment('Changing the keys later invalidates every subscribed browser: each admin has to enable the bell again.');

        return self::SUCCESS;
    }
}

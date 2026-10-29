<?php

namespace App\Console\Commands;

use App\Domain\Cart\Models\Cart;
use Illuminate\Console\Command;

/**
 * Housekeeping for abandoned carts (scheduled daily):
 *  - guest carts whose 30 days are over are deleted (their lines go with them)
 *  - empty customer carts untouched for `--days` days (default 90) are deleted; carts with items are kept for the customer
 */
class PruneCarts extends Command
{
    protected $signature = 'carts:prune {--days=90 : delete empty customer carts untouched for this many days}';

    protected $description = 'Delete expired guest carts and old empty customer carts';

    public function handle(): int
    {
        $guests = Cart::query()->whereNull('user_id')->where('expires_at', '<', now())->delete();

        $days = max(1, (int) $this->option('days'));
        $empty = Cart::query()->whereNotNull('user_id')->where('updated_at', '<', now()->subDays($days))
            ->whereDoesntHave('items')->delete();

        $this->info("Deleted $guests expired guest carts and $empty old empty customer carts.");

        return self::SUCCESS;
    }
}

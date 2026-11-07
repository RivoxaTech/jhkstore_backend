<?php

namespace App\Console\Commands;

use App\Domain\Cart\Actions\ManageCart;
use Illuminate\Console\Command;

class PruneCarts extends Command
{
    protected $signature = 'shop:prune-carts';

    protected $description = 'Delete the carts that expired (30 days after their last activity). Scheduled daily.';

    public function handle(ManageCart $carts): int
    {
        $this->info('Deleted '.$carts->prune().' expired cart(s).');

        return self::SUCCESS;
    }
}

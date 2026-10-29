<?php

namespace App\Console\Commands;

use App\Domain\Reward\Actions\RewardLedger;
use Illuminate\Console\Command;

/** Coins expire `reward_expiry_days` after they were credited (scheduled daily): the batch is marked expired and the wallet balance drops. */
class ExpireRewardCoins extends Command
{
    protected $signature = 'rewards:expire';

    protected $description = 'Expire coin batches that are past their expiry date';

    public function handle(RewardLedger $ledger): int
    {
        $this->info('Expired '.$ledger->expireDue().' coin batches.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Website\Rebuild;
use Illuminate\Console\Command;

class RebuildWebsite extends Command
{
    protected $signature = 'shop:rebuild';

    protected $description = 'Ask the hosting to rebuild the static website now (calls the rebuild hook of Settings > Website).';

    public function handle(Rebuild $rebuild): int
    {
        if (! $rebuild->configured()) {
            $this->error('No rebuild hook is set: Settings > Website.');

            return self::FAILURE;
        }
        $result = $rebuild->now(null, 'console');
        if ($result['ok']) {
            $this->info('Rebuild requested (HTTP '.$result['status'].').');

            return self::SUCCESS;
        }
        $this->error('Rebuild failed: '.$result['error']);

        return self::FAILURE;
    }
}

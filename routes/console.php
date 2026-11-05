<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired admin API tokens are removed a day after they ran out.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:45');

// Carts that were not touched for 30 days are removed.
Schedule::command('shop:prune-carts')->dailyAt('04:10');

// A tiny job proves a queue worker is running (Settings > Notifications shows it). Needs the scheduler (cron) like the pruning above.
Schedule::command('shop:queue-heartbeat')->everyMinute();

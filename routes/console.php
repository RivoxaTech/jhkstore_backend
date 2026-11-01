<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping: expired guest carts and old empty carts, expired coins.
Schedule::command('carts:prune')->dailyAt('03:15');
Schedule::command('rewards:expire')->dailyAt('03:30');
// Expired API tokens (customer and admin) are removed a day after they ran out.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:45');

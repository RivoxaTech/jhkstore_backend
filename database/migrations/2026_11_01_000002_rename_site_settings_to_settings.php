<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The key/value table is now simply `settings`; the coin settings get their new names. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('site_settings', 'settings');

        DB::table('settings')->where('key', 'coin_value')->update(['key' => 'reward_redeem_rate']);
    }

    public function down(): void
    {
        Schema::rename('settings', 'site_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The pincodes table keeps serviceability and cash-on-delivery per code; the fast-delivery channel columns are gone with the channels. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pincodes', function (Blueprint $table) {
            $table->dropColumn(['instadent_available', 'instadent_eta_days']);
        });
    }

    public function down(): void
    {
        // One-way clean-up.
    }
};

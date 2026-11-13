<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The business registration number (BRN) a wholesale customer may give: optional, shown to the owner next to the business name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_requests', function (Blueprint $table) {
            $table->string('brn', 50)->nullable()->after('business_name');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_requests', function (Blueprint $table) {
            $table->dropColumn('brn');
        });
    }
};

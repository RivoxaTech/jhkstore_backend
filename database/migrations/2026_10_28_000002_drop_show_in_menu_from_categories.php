<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A category is simply active or not; a separate "show in menu" switch was never needed. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'sub_categories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('show_in_menu'));
        }
    }

    public function down(): void
    {
        foreach (['categories', 'sub_categories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('show_in_menu')->default(true)->after('is_active'));
        }
    }
};

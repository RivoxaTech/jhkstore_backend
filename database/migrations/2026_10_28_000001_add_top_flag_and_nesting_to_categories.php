<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_top')->default(false)->after('show_in_menu');
            $table->index('is_top');
        });

        // A sub-category may have its own children (sub-sub-categories); one level only, enforced by the admin API.
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('category_id')->constrained('sub_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['is_top']);
            $table->dropColumn('is_top');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categories are two levels only (category > sub-category). Products of a former sub-sub-category move up to its parent
 * sub-category, the sub-sub-categories are deleted and `sub_categories.parent_id` goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE products p JOIN sub_categories s ON s.id = p.sub_category_id SET p.sub_category_id = s.parent_id WHERE s.parent_id IS NOT NULL');
        DB::table('sub_categories')->whereNotNull('parent_id')->delete();

        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }

    public function down(): void
    {
        // One-way clean-up: the third category level was removed from the product.
    }
};

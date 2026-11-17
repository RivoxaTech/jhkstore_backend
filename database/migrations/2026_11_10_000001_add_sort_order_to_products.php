<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop owner arranges the products by drag and drop (Products screen): `sort_order` is the position (1..n after an arrangement, 0 for a product
 * nobody placed yet: it shows first, newest first). The storefront default order and the admin list follow it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('popularity_score');
            $table->index(['status', 'sort_order'], 'products_status_sort_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_status_sort_order_index');
            $table->dropColumn('sort_order');
        });
    }
};

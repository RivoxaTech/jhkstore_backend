<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes sales channels. Stock now lives on the variant (`stock_qty`, `reserved_qty`, `low_stock_threshold`);
 * carts, orders and the content/promotion tables no longer have a channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_qty')->default(0)->after('is_active');
            $table->integer('reserved_qty')->default(0)->after('stock_qty');
            $table->integer('low_stock_threshold')->default(5)->after('reserved_qty');
        });

        // Keep the stock of the first channel (a no-op on a fresh install).
        DB::statement('UPDATE product_variants v JOIN variant_channel_stock s ON s.variant_id = v.id
            AND s.channel_id = (SELECT MIN(id) FROM sales_channels)
            SET v.stock_qty = s.stock_qty, v.reserved_qty = s.reserved_qty, v.low_stock_threshold = s.low_stock_threshold');

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['variant_id', 'created_at'], 'inventory_movements_variant_created_index');
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['variant_id', 'channel_id', 'created_at']);
            $table->dropColumn('channel_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'carts_user_status_index');
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['user_id', 'channel_id', 'status']);
            $table->dropColumn('channel_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['channel_id', 'status']);
            $table->dropColumn('channel_id');
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropIndex(['placement', 'channel_id', 'is_active', 'sort_order']);
            $table->dropColumn('channel_id');
            $table->index(['placement', 'is_active', 'sort_order']);
        });

        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropUnique(['channel_id', 'key']);
            $table->dropColumn('channel_id');
            $table->unique('key');
        });

        foreach (['coupons', 'collections', 'shipping_rules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['channel_id']);
                $table->dropColumn('channel_id');
            });
        }

        Schema::dropIfExists('variant_channel_stock');
        Schema::dropIfExists('product_channels');
        Schema::dropIfExists('sales_channels');
    }

    public function down(): void
    {
        // One-way clean-up: sales channels were removed from the product.
    }
};

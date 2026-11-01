<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the seller / company onboarding flow. Brands stay (standalone, optional on products); products and order
 * lines no longer reference companies; the company lookup tables (registration types, vendor types, countries) go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn('company_id');
            $table->dropForeign(['brand_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'company_name']);
            $table->dropForeign(['brand_id']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropForeign(['origin_country_id']);
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'name']);
            $table->dropColumn(['origin_country_id', 'company_id']);
        });
        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->change();
        });

        Schema::dropIfExists('companies');
        Schema::dropIfExists('vendor_types');
        Schema::dropIfExists('company_registration_types');
        Schema::dropIfExists('countries');
    }

    public function down(): void
    {
        // One-way clean-up: the company flow was removed from the product.
    }
};

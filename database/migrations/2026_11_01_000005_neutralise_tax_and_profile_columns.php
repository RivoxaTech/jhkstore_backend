<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the schema market-neutral:
 *  - tax: `products.tax_rate` (null = the store's default rate) replaces gst_rate / hsn_code; tax numbers are plain `tax_number` columns;
 *    orders remember whether prices were tax inclusive
 *  - customers: the dental profile fields (professional type, speciality, council number, verification) go
 *  - no hard-coded country or currency defaults
 *  - `products.is_professional_only` goes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('gst_rate', 'tax_rate');
            $table->dropColumn(['hsn_code', 'is_professional_only']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->default(null)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('hsn_code');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('customer_gst_number', 'customer_tax_number');
            $table->boolean('tax_inclusive')->default(true)->after('tax_total');
        });

        foreach (['user_addresses', 'order_addresses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->renameColumn('gst_number', 'tax_number');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->string('tax_number', 40)->nullable()->change();
                $table->string('country', 60)->default('')->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['professional_type', 'speciality', 'council_reg_no', 'anniversary', 'verification_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->default('USD')->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('seller_gstin', 'seller_tax_id');
            $table->renameColumn('buyer_gstin', 'buyer_tax_id');
        });
    }

    public function down(): void
    {
        // One-way clean-up: the market-specific columns were removed from the product.
    }
};

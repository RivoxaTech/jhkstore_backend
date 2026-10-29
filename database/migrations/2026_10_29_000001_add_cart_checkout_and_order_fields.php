<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cart, checkout and orders: what the first schema was missing.
 *  - addresses get the Dentalkart edit-address fields (alternate phone, area, coordinates; `pincode` is called `postcode`)
 *  - order lines snapshot the option name, brand/company names and the bulk-price details
 *  - orders snapshot the customer (searchable), count items, split the discounts and carry an idempotency key + an internal admin note
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['user_addresses', 'order_addresses'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('line2', 'area');           // "Area / Street / Sector / Village"
                $t->renameColumn('pincode', 'postcode');
            });
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('alt_phone', 20)->nullable()->after('phone');
                $t->decimal('latitude', 10, 7)->nullable();
                $t->decimal('longitude', 10, 7)->nullable();
                if ($table === 'order_addresses') {
                    $t->string('address_type', 20)->nullable();   // clinic | home | other (the `type` column here is shipping | billing)
                }
                $t->string('country', 60)->default('Pakistan')->change();
            });
        }

        Schema::table('orders', function (Blueprint $t) {
            $t->string('status', 25)->default('pending')->change();
            $t->string('customer_name', 120)->nullable()->after('user_id');
            $t->string('customer_email', 190)->nullable()->after('customer_name');
            $t->string('customer_phone', 20)->nullable()->after('customer_email');
            $t->unsignedSmallInteger('items_count')->default(0)->after('payment_method');            // units in the order
            $t->decimal('product_discount_total', 12, 2)->default(0)->after('mrp_total');          // MRP -> option price
            $t->decimal('bulk_discount_total', 12, 2)->default(0)->after('product_discount_total'); // option price -> bulk price
            $t->text('admin_note')->nullable();                                                    // internal, never shown to the customer
            $t->string('idempotency_key', 64)->nullable();
            $t->unique(['user_id', 'idempotency_key']);
            $t->index('created_at');
            $t->index('customer_email');
            $t->index('customer_phone');
            $t->fullText('customer_name');
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->string('option_name', 255)->nullable()->after('name');       // `name` = the product, this = the option
            $t->string('brand_name', 160)->nullable()->after('option_name');
            $t->string('company_name', 160)->nullable()->after('brand_name');
            $t->decimal('base_price', 12, 2)->nullable()->after('mrp');      // the option's own price (before a bulk price)
            $t->unsignedSmallInteger('bulk_min_qty')->nullable()->after('unit_price'); // the bulk tier that was used ("Buy 5+")
            $t->decimal('bulk_discount', 12, 2)->default(0)->after('discount');       // (base_price - unit_price) x qty
        });

        // Settings introduced with checkout. Existing values are never overwritten (only added when missing).
        $now = now();
        foreach ([
            ['orders', 'min_order_amount', 0],
            ['rewards', 'max_redeem_coins_per_order', 5000],
            ['rewards', 'redeem_cap_order_percent', 100],
            ['rewards', 'redeem_cap_balance_percent', 100],
        ] as [$group, $key, $value]) {
            if (! DB::table('site_settings')->where('key', $key)->exists()) {
                DB::table('site_settings')->insert(['group_key' => $group, 'key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // 1 coin = Rs 1 now. Only the old untouched default (1.5) is changed; a value the admin chose is left alone.
        DB::table('site_settings')->where('key', 'coin_value')->where('value', json_encode(1.5))->update(['value' => json_encode(1), 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropColumn(['option_name', 'brand_name', 'company_name', 'base_price', 'bulk_min_qty', 'bulk_discount']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropFullText(['customer_name']);
            $t->dropIndex(['customer_phone']);
            $t->dropIndex(['customer_email']);
            $t->dropIndex(['created_at']);
            $t->dropUnique(['user_id', 'idempotency_key']);
            $t->dropColumn(['customer_name', 'customer_email', 'customer_phone', 'items_count', 'product_discount_total', 'bulk_discount_total', 'admin_note', 'idempotency_key']);
            $t->string('status', 25)->default('pending_payment')->change();
        });
        foreach (['user_addresses', 'order_addresses'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropColumn($table === 'order_addresses' ? ['alt_phone', 'latitude', 'longitude', 'address_type'] : ['alt_phone', 'latitude', 'longitude']);
                $t->string('country', 60)->default('India')->change();
            });
            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('area', 'line2');
                $t->renameColumn('postcode', 'pincode');
            });
        }
    }
};

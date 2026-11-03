<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complete schema of the lean store for a FRESH install: products, categories, orders, customers (a contact record, no login),
 * settings, banners, delivery zones, discounts, coupons, the persistent cart, the two admin accounts with their roles, and the framework tables. See docs/DATABASE.md for every table.
 *
 * Tables are created first, foreign keys are added afterwards so the order of the tables does not matter.
 * New changes go into new migration files after this one. A database created from an earlier version of this file must be rebuilt (migrate:fresh).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Framework, admin accounts and permissions
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->primary(['id']);
            $table->index(['last_activity'], 'sessions_last_activity_index');
            $table->index(['user_id'], 'sessions_user_id_index');
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 255);
            $table->mediumText('value');
            $table->bigInteger('expiration');
            $table->primary(['key']);
            $table->index(['expiration'], 'cache_expiration_index');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 255);
            $table->string('owner', 255);
            $table->bigInteger('expiration');
            $table->primary(['key']);
            $table->index(['expiration'], 'cache_locks_expiration_index');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 255);
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue'], 'jobs_queue_index');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('name', 255);
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 255);
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at'], 'failed_jobs_connection_queue_failed_at_index');
            $table->unique(['uuid'], 'failed_jobs_uuid_unique');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 255);
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64);
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['expires_at'], 'personal_access_tokens_expires_at_index');
            $table->unique(['token'], 'personal_access_tokens_token_unique');
            $table->index(['tokenable_type', 'tokenable_id'], 'personal_access_tokens_tokenable_type_tokenable_id_index');
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 60);
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['email'], 'admins_email_unique');
            $table->unique(['username'], 'admins_username_unique');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('guard_name', 255);
            $table->timestamps();
            $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('guard_name', 255);
            $table->timestamps();
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 255);
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 255);
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
            $table->index(['role_id'], 'role_has_permissions_role_id_foreign');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('model_type', 255);
            $table->unsignedBigInteger('model_id');
            $table->char('uuid', 36)->nullable();
            $table->string('collection_name', 255);
            $table->string('name', 255);
            $table->string('file_name', 255);
            $table->string('mime_type', 255)->nullable();
            $table->string('disk', 255);
            $table->string('conversions_disk', 255)->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->timestamps();
            $table->index(['model_type', 'model_id'], 'media_model_type_model_id_index');
            $table->index(['order_column'], 'media_order_column_index');
            $table->unique(['uuid'], 'media_uuid_unique');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['actor_type', 'actor_id', 'created_at'], 'audit_logs_actor_type_actor_id_created_at_index');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_type_subject_id_index');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 40);
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['key'], 'site_settings_key_unique');
        });

        // ---- Customers: a contact record created or updated by every order. No password, no login.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 20);                  // normalised (see App\Domain\Customer\Phone), the identity of the customer
            $table->string('email', 190)->nullable();
            $table->string('city', 100)->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 14, 2)->default(0.00);
            $table->timestamps();
            $table->unique(['phone'], 'customers_phone_unique');
            $table->index(['email'], 'customers_email_index');
            $table->index(['name'], 'customers_name_index');
            $table->index(['last_order_at'], 'customers_last_order_at_index');
        });

        // ---- Catalogue
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_top')->default(false);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['is_top'], 'categories_is_top_index');
            $table->unique(['slug'], 'categories_slug_unique');
        });

        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['category_id', 'slug'], 'sub_categories_category_id_slug_unique');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80);
            $table->string('color', 9)->nullable();
            $table->string('style', 20)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['slug'], 'tags_slug_unique');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 280);
            $table->string('sku', 80);
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('badge_text', 40)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('min_order_qty')->default(1);
            $table->unsignedSmallInteger('max_order_qty')->default(10);
            $table->boolean('is_cod_available')->default(true);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->decimal('min_price', 12, 2)->nullable();
            $table->decimal('max_discount_percent', 5, 2)->default(0.00);
            $table->boolean('is_in_stock')->default(false);
            $table->unsignedInteger('popularity_score')->default(0);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['category_id', 'status', 'min_price'], 'products_category_id_status_min_price_index');
            $table->index(['name'], 'products_name_index');
            $table->fullText(['name', 'short_description'], 'products_name_short_description_fulltext');
            $table->unique(['sku'], 'products_sku_unique');
            $table->unique(['slug'], 'products_slug_unique');
            $table->index(['status', 'popularity_score'], 'products_status_popularity_score_index');
            $table->index(['status', 'published_at'], 'products_status_published_at_index');
            $table->index(['sub_category_id', 'status', 'min_price'], 'products_sub_category_id_status_min_price_index');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 80);
            $table->string('name', 255);
            $table->boolean('is_default')->default(false);
            $table->json('attributes')->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0.00);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->decimal('width_cm', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->boolean('is_cod_available')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('stock_qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['discount_percent'], 'product_variants_discount_percent_index');
            $table->index(['product_id', 'is_active'], 'product_variants_product_id_is_active_index');
            $table->index(['selling_price'], 'product_variants_selling_price_index');
            $table->unique(['sku'], 'product_variants_sku_unique');
        });

        Schema::create('variant_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id');
            $table->unsignedSmallInteger('min_qty');
            $table->decimal('price', 12, 2);
            $table->timestamps();
            $table->unique(['variant_id', 'min_qty'], 'variant_price_tiers_variant_id_min_qty_unique');
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['product_id', 'tag_id'], 'product_tag_product_id_tag_id_unique');
            $table->index(['tag_id', 'product_id'], 'product_tag_tag_id_product_id_index');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('type', 10)->default('image');
            $table->string('url', 500)->nullable();
            $table->string('alt', 160)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'sort_order'], 'product_images_product_id_sort_order_index');
            $table->index(['variant_id'], 'product_images_variant_id_foreign');
        });

        Schema::create('product_content_tabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('tab_key', 30);
            $table->string('format', 10)->default('paragraphs');
            $table->longText('content')->nullable();
            $table->string('manual_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'tab_key'], 'product_content_tabs_product_id_tab_key_unique');
        });

        Schema::create('product_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('related_product_id');
            $table->string('type', 20)->default('related');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['product_id', 'related_product_id', 'type'], 'product_relations_product_id_related_product_id_type_unique');
            $table->index(['related_product_id'], 'product_relations_related_product_id_foreign');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id');
            $table->string('type', 20);
            $table->integer('qty_change');
            $table->integer('balance_after');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['admin_id'], 'inventory_movements_admin_id_foreign');
            $table->index(['variant_id', 'created_at'], 'inventory_movements_variant_created_index');
        });

        // ---- Orders, payments and shipping
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30);
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('status', 25)->default('pending');
            $table->string('payment_status', 20)->default('pending');
            $table->string('fulfillment_status', 20)->default('unfulfilled');
            $table->string('payment_method', 20)->nullable();
            $table->unsignedSmallInteger('items_count')->default(0);
            $table->decimal('items_subtotal', 12, 2);
            $table->decimal('mrp_total', 12, 2);
            $table->decimal('product_discount_total', 12, 2)->default(0.00);
            $table->decimal('bulk_discount_total', 12, 2)->default(0.00);
            $table->decimal('discount_total', 12, 2)->default(0.00);      // automatic discounts (the coupon is separate)
            $table->decimal('coupon_discount', 12, 2)->default(0.00);
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_code', 32)->nullable();
            $table->decimal('shipping_fee', 12, 2)->nullable();            // null while the delivery fee is still to be confirmed
            $table->boolean('delivery_fee_pending')->default(false);
            $table->unsignedBigInteger('delivery_zone_id')->nullable();
            $table->decimal('tax_total', 12, 2)->default(0.00);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('grand_total', 12, 2);
            $table->string('customer_note', 500)->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->string('source', 10)->default('web');
            $table->timestamps();
            $table->text('admin_note')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->index(['created_at'], 'orders_created_at_index');
            $table->index(['customer_email'], 'orders_customer_email_index');
            $table->fullText(['customer_name'], 'orders_customer_name_fulltext');
            $table->index(['customer_phone'], 'orders_customer_phone_index');
            $table->unique(['order_number'], 'orders_order_number_unique');
            $table->index(['payment_status'], 'orders_payment_status_index');
            $table->index(['coupon_id'], 'orders_coupon_id_index');
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            $table->index(['customer_id', 'created_at'], 'orders_customer_id_created_at_index');
            $table->unique(['customer_id', 'idempotency_key'], 'orders_customer_id_idempotency_key_unique');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 80);
            $table->string('name', 255);
            $table->string('option_name', 255)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('bulk_min_qty')->nullable();
            $table->unsignedSmallInteger('qty');
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('bulk_discount', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 12, 2)->default(0.00);      // saving of a discount or coupon on the line (bulk is `bulk_discount`)
            $table->string('discount_source', 10)->nullable();             // bulk | discount | coupon
            $table->unsignedBigInteger('discount_source_id')->nullable();  // the discount or coupon id
            $table->decimal('tax_rate', 4, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('line_total', 12, 2);
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('cancelled_qty')->default(0);
            $table->timestamps();
            $table->index(['order_id'], 'order_items_order_id_foreign');
            $table->index(['product_id'], 'order_items_product_id_index');
            $table->index(['variant_id'], 'order_items_variant_id_foreign');
        });

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('type', 10);
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('line1', 255);
            $table->string('area', 255)->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('city', 100);
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 60)->default('');
            $table->timestamps();
            $table->unique(['order_id', 'type'], 'order_addresses_order_id_type_unique');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->string('note', 255)->nullable();
            $table->string('changed_by_type', 10)->default('system');
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->boolean('is_customer_visible')->default(true);
            $table->timestamps();
            $table->index(['order_id', 'created_at'], 'order_status_history_order_id_created_at_index');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type', 40);
            $table->unsignedBigInteger('payable_id');
            $table->string('method', 20);
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_order_id', 80)->nullable();
            $table->string('gateway_payment_id', 80)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 20)->default('initiated');
            $table->string('failure_reason', 255)->nullable();
            $table->json('response')->nullable();
            $table->json('bank_snapshot')->nullable();                     // the accounts and instructions shown to the customer when the order was placed
            $table->timestamp('paid_at')->nullable();
            $table->string('proof_status', 12)->default('none');          // none | submitted | verified | rejected (bank transfer)
            $table->string('proof_reference', 120)->nullable();
            $table->string('proof_reject_reason', 300)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamps();
            $table->unique(['gateway_order_id'], 'payments_gateway_order_id_unique');
            $table->unique(['gateway_payment_id'], 'payments_gateway_payment_id_unique');
            $table->index(['payable_type', 'payable_id'], 'payments_payable_type_payable_id_index');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('shipment_number', 30);
            $table->string('carrier', 60)->nullable();
            $table->string('awb_number', 60)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 25)->default('created');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['awb_number'], 'shipments_awb_number_index');
            $table->index(['order_id'], 'shipments_order_id_foreign');
            $table->unique(['shipment_number'], 'shipments_shipment_number_unique');
        });

        // ---- Content
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 30);
            $table->string('title', 160)->nullable();
            $table->string('subtitle', 255)->nullable();
            $table->string('button_text', 60)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['placement', 'is_active', 'sort_order'], 'banners_placement_is_active_sort_order_index');
        });

        // ---- Delivery, discounts, coupons and the persistent cart
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('match_type', 10);                 // city | area | postcode
            $table->json('match_values');                      // list of names / postcodes (a postcode may end with *)
            $table->decimal('fee', 12, 2)->default(0.00);
            $table->decimal('free_above', 12, 2)->nullable();
            $table->decimal('min_order', 12, 2)->nullable();   // replaces the store minimum for orders in this zone
            $table->string('eta_text', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'delivery_zones_is_active_sort_order_index');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 10);                        // percent | fixed
            $table->decimal('value', 12, 2);
            $table->string('scope', 20);                       // store | products | categories | sub_categories
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at'], 'discounts_active_window_index');
        });

        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('discount_id');
            $table->string('type', 20);                        // product | category | sub_category
            $table->unsignedBigInteger('target_id');
            $table->unique(['discount_id', 'type', 'target_id'], 'discount_targets_unique');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('type', 16);                        // percent | fixed | free_delivery
            $table->decimal('value', 12, 2)->nullable();       // null for a free-delivery coupon
            $table->string('scope', 20);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('min_order', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->boolean('free_delivery')->default(false);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
            $table->unique(['code'], 'coupons_code_unique');
        });

        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->string('type', 20);
            $table->unsignedBigInteger('target_id');
            $table->unique(['coupon_id', 'type', 'target_id'], 'coupon_targets_unique');
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('phone', 20)->nullable();           // normalised, the per-customer limit counts these
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->timestamp('released_at')->nullable();      // set when the order was cancelled or rejected: the use is given back
            $table->timestamps();
            $table->unique(['coupon_id', 'order_id'], 'coupon_usages_coupon_order_unique');
            $table->index(['coupon_id', 'phone'], 'coupon_usages_coupon_phone_index');
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->char('token', 40);
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('coupon_code', 32)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['token'], 'carts_token_unique');
            $table->index(['expires_at'], 'carts_expires_at_index');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('variant_id');          // no foreign key on purpose: an option that was deleted stays in the cart as an unavailable line
            $table->unsignedInteger('qty');
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id'], 'cart_items_cart_variant_unique');
        });

        // ---- Foreign keys
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->foreign('permission_id', 'model_has_permissions_permission_id_foreign')->references('id')->on('permissions')->onDelete('cascade');
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->foreign('role_id', 'model_has_roles_role_id_foreign')->references('id')->on('roles')->onDelete('cascade');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->foreign('permission_id', 'role_has_permissions_permission_id_foreign')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('role_id', 'role_has_permissions_role_id_foreign')->references('id')->on('roles')->onDelete('cascade');
        });

        Schema::table('sub_categories', function (Blueprint $table) {
            $table->foreign('category_id', 'sub_categories_category_id_foreign')->references('id')->on('categories')->onDelete('restrict');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id', 'products_category_id_foreign')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('sub_category_id', 'products_sub_category_id_foreign')->references('id')->on('sub_categories')->onDelete('restrict');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreign('product_id', 'product_variants_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('variant_price_tiers', function (Blueprint $table) {
            $table->foreign('variant_id', 'variant_price_tiers_variant_id_foreign')->references('id')->on('product_variants')->onDelete('cascade');
        });

        Schema::table('product_tag', function (Blueprint $table) {
            $table->foreign('product_id', 'product_tag_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('tag_id', 'product_tag_tag_id_foreign')->references('id')->on('tags')->onDelete('cascade');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->foreign('product_id', 'product_images_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variant_id', 'product_images_variant_id_foreign')->references('id')->on('product_variants')->onDelete('set null');
        });

        Schema::table('product_content_tabs', function (Blueprint $table) {
            $table->foreign('product_id', 'product_content_tabs_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('product_relations', function (Blueprint $table) {
            $table->foreign('product_id', 'product_relations_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('related_product_id', 'product_relations_related_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('admin_id', 'inventory_movements_admin_id_foreign')->references('id')->on('admins')->onDelete('set null');
            $table->foreign('variant_id', 'inventory_movements_variant_id_foreign')->references('id')->on('product_variants')->onDelete('cascade');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('customer_id', 'orders_customer_id_foreign')->references('id')->on('customers')->onDelete('restrict');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('order_id', 'order_items_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('product_id', 'order_items_product_id_foreign')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variant_id', 'order_items_variant_id_foreign')->references('id')->on('product_variants')->onDelete('restrict');
        });

        Schema::table('order_addresses', function (Blueprint $table) {
            $table->foreign('order_id', 'order_addresses_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->foreign('order_id', 'order_status_history_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('order_id', 'shipments_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('discount_targets', function (Blueprint $table) {
            $table->foreign('discount_id', 'discount_targets_discount_id_foreign')->references('id')->on('discounts')->onDelete('cascade');
        });

        Schema::table('coupon_targets', function (Blueprint $table) {
            $table->foreign('coupon_id', 'coupon_targets_coupon_id_foreign')->references('id')->on('coupons')->onDelete('cascade');
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreign('coupon_id', 'coupon_usages_coupon_id_foreign')->references('id')->on('coupons')->onDelete('restrict');
            $table->foreign('order_id', 'coupon_usages_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreign('cart_id', 'cart_items_cart_id_foreign')->references('id')->on('carts')->onDelete('cascade');
        });

    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'cart_items',
            'carts',
            'coupon_usages',
            'coupon_targets',
            'coupons',
            'discount_targets',
            'discounts',
            'delivery_zones',
            'banners',
            'payments',
            'shipments',
            'order_status_history',
            'order_addresses',
            'order_items',
            'orders',
            'inventory_movements',
            'product_relations',
            'product_content_tabs',
            'product_images',
            'product_tag',
            'variant_price_tiers',
            'product_variants',
            'products',
            'tags',
            'sub_categories',
            'categories',
            'customers',
            'settings',
            'audit_logs',
            'media',
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'roles',
            'permissions',
            'admins',
            'personal_access_tokens',
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache_locks',
            'cache',
            'sessions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};

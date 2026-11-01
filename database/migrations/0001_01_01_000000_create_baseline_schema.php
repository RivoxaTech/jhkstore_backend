<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complete schema of the e-commerce base for a FRESH install (squashed from the project's earlier migrations).
 *
 * Tables are created first, foreign keys are added afterwards so the order of the tables does not matter. Tables for features that are
 * planned but not built yet (wishlist, coupons, CMS, notifications, refunds, invoices, ...) are included on purpose; see docs/DATABASE.md.
 * New changes go into new migration files after this one. An existing database that ran the old migrations must be rebuilt (migrate:fresh).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Framework, authentication and permissions
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 120);
            $table->string('email', 190)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('password', 255)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['email'], 'users_email_unique');
            $table->unique(['mobile'], 'users_mobile_unique');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255);
            $table->string('token', 255);
            $table->timestamp('created_at')->nullable();
            $table->primary(['email']);
        });

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

        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36);
            $table->string('type', 255);
            $table->string('notifiable_type', 255);
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->string('category', 15)->default('system');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->primary(['id']);
            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_type_notifiable_id_index');
        });

        // ---- Customers
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('label', 40)->nullable();
            $table->string('type', 20)->default('home');
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('alt_phone', 20)->nullable();
            $table->string('line1', 255);
            $table->string('area', 255)->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postcode', 10);
            $table->string('country', 60)->default('');
            $table->string('tax_number', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->index(['user_id'], 'user_addresses_user_id_foreign');
        });

        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->boolean('is_serviceable')->default(true);
            $table->boolean('cod_available')->default(true);
            $table->unsignedTinyInteger('standard_eta_days')->nullable();
            $table->timestamps();
            $table->unique(['pincode'], 'pincodes_pincode_unique');
        });

        // ---- Catalogue
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
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
            $table->decimal('rating', 2, 1)->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['category_id', 'slug'], 'sub_categories_category_id_slug_unique');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['name'], 'brands_name_index');
            $table->unique(['slug'], 'brands_slug_unique');
        });

        Schema::create('brand_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->string('collection', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['brand_id', 'collection'], 'brand_collections_brand_id_collection_unique');
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
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 280);
            $table->string('sku', 80);
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('badge_text', 40)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('country_of_origin', 80)->nullable();
            $table->string('warranty_text', 255)->nullable();
            $table->unsignedSmallInteger('min_order_qty')->default(1);
            $table->unsignedSmallInteger('max_order_qty')->default(10);
            $table->boolean('is_returnable')->default(true);
            $table->unsignedSmallInteger('return_days')->default(10);
            $table->boolean('is_cod_available')->default(true);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->decimal('min_price', 12, 2)->nullable();
            $table->decimal('max_discount_percent', 5, 2)->default(0.00);
            $table->decimal('rating_avg', 2, 1)->default(0.0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_in_stock')->default(false);
            $table->unsignedInteger('popularity_score')->default(0);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['brand_id', 'status'], 'products_brand_id_status_index');
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
            $table->unsignedInteger('reward_points')->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('barcode', 40)->nullable();
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

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 10)->default('manual');
            $table->unsignedBigInteger('tag_id')->nullable();
            $table->json('rule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['key'], 'collections_key_unique');
            $table->index(['tag_id'], 'collections_tag_id_foreign');
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collection_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['collection_id', 'product_id'], 'collection_product_collection_id_product_id_unique');
            $table->index(['product_id'], 'collection_product_product_id_foreign');
        });

        // ---- Reviews
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('published');
            $table->boolean('is_verified_purchase')->default(false);
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['order_item_id'], 'product_reviews_order_item_id_foreign');
            $table->index(['product_id', 'status', 'created_at'], 'product_reviews_product_id_status_created_at_index');
            $table->unique(['product_id', 'user_id'], 'product_reviews_product_id_user_id_unique');
            $table->index(['user_id'], 'product_reviews_user_id_foreign');
        });

        Schema::create('product_rating_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->decimal('avg', 3, 2)->default(0.00);
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('c1')->default(0);
            $table->unsignedInteger('c2')->default(0);
            $table->unsignedInteger('c3')->default(0);
            $table->unsignedInteger('c4')->default(0);
            $table->unsignedInteger('c5')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['product_id']);
        });

        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->unsignedBigInteger('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['answered_by'], 'product_questions_answered_by_foreign');
            $table->index(['product_id', 'status'], 'product_questions_product_id_status_index');
            $table->index(['user_id'], 'product_questions_user_id_foreign');
        });

        Schema::create('product_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('purchased')->nullable();
            $table->unsignedTinyInteger('quality_rating')->nullable();
            $table->unsignedTinyInteger('price_rating')->nullable();
            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['product_id'], 'product_feedback_product_id_foreign');
            $table->index(['user_id'], 'product_feedback_user_id_foreign');
        });

        // ---- Wishlist
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->string('visibility', 10)->default('public');
            $table->char('share_token', 26)->nullable();
            $table->timestamps();
            $table->unique(['share_token'], 'wishlists_share_token_unique');
            $table->unique(['user_id', 'name'], 'wishlists_user_id_name_unique');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wishlist_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->timestamps();
            $table->index(['product_id'], 'wishlist_items_product_id_index');
            $table->index(['variant_id'], 'wishlist_items_variant_id_foreign');
            $table->unique(['wishlist_id', 'variant_id'], 'wishlist_items_wishlist_id_variant_id_unique');
        });

        Schema::create('wishlist_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wishlist_id');
            $table->unsignedBigInteger('shared_with_user_id');
            $table->timestamps();
            $table->index(['shared_with_user_id'], 'wishlist_shares_shared_with_user_id_foreign');
            $table->unique(['wishlist_id', 'shared_with_user_id'], 'wishlist_shares_wishlist_id_shared_with_user_id_unique');
        });

        // ---- Promotions
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->string('discount_type', 10);
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_cart_value', 10, 2)->default(0.00);
            $table->string('audience', 15)->default('all');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_stackable')->default(false);
            $table->boolean('show_in_my_coupons')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code'], 'coupons_code_unique');
        });

        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->string('target_type', 15);
            $table->unsignedBigInteger('target_id');
            $table->index(['coupon_id', 'target_type'], 'coupon_targets_coupon_id_target_type_index');
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('discount_amount', 10, 2);
            $table->timestamps();
            $table->unique(['coupon_id', 'order_id'], 'coupon_usages_coupon_id_order_id_unique');
            $table->index(['order_id'], 'coupon_usages_order_id_foreign');
            $table->index(['user_id'], 'coupon_usages_user_id_foreign');
        });

        // ---- Cart, orders, payments and shipping
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('guest_token', 40)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedInteger('coins_to_redeem')->default(0);
            $table->string('status', 15)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id'], 'carts_coupon_id_foreign');
            $table->unique(['guest_token'], 'carts_guest_token_unique');
            $table->index(['user_id', 'status'], 'carts_user_status_index');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedSmallInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id'], 'cart_items_cart_id_variant_id_unique');
            $table->index(['variant_id'], 'cart_items_variant_id_foreign');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30);
            $table->unsignedBigInteger('user_id');
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
            $table->decimal('discount_total', 12, 2)->default(0.00);
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedInteger('coins_redeemed')->default(0);
            $table->decimal('coins_discount', 12, 2)->default(0.00);
            $table->decimal('shipping_fee', 12, 2)->default(0.00);
            $table->decimal('cod_fee', 12, 2)->default(0.00);
            $table->decimal('tax_total', 12, 2)->default(0.00);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('grand_total', 12, 2);
            $table->unsignedInteger('coins_to_earn')->default(0);
            $table->string('customer_tax_number', 20)->nullable();
            $table->string('customer_note', 500)->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('source', 10)->default('web');
            $table->boolean('membership_applied')->default(false);
            $table->timestamps();
            $table->text('admin_note')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->index(['coupon_id'], 'orders_coupon_id_foreign');
            $table->index(['created_at'], 'orders_created_at_index');
            $table->index(['customer_email'], 'orders_customer_email_index');
            $table->fullText(['customer_name'], 'orders_customer_name_fulltext');
            $table->index(['customer_phone'], 'orders_customer_phone_index');
            $table->unique(['order_number'], 'orders_order_number_unique');
            $table->index(['payment_status'], 'orders_payment_status_index');
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            $table->index(['user_id', 'created_at'], 'orders_user_id_created_at_index');
            $table->unique(['user_id', 'idempotency_key'], 'orders_user_id_idempotency_key_unique');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('sku', 80);
            $table->string('name', 255);
            $table->string('option_name', 255)->nullable();
            $table->string('brand_name', 160)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('bulk_min_qty')->nullable();
            $table->unsignedSmallInteger('qty');
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('bulk_discount', 12, 2)->default(0.00);
            $table->decimal('tax_rate', 4, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('line_total', 12, 2);
            $table->unsignedInteger('coins_earned')->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('cancelled_qty')->default(0);
            $table->unsignedSmallInteger('returned_qty')->default(0);
            $table->timestamps();
            $table->index(['brand_id'], 'order_items_brand_id_foreign');
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
            $table->string('alt_phone', 20)->nullable();
            $table->string('line1', 255);
            $table->string('area', 255)->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postcode', 10);
            $table->string('country', 60)->default('');
            $table->string('tax_number', 40)->nullable();
            $table->timestamps();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address_type', 20)->nullable();
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
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway_order_id'], 'payments_gateway_order_id_unique');
            $table->unique(['gateway_payment_id'], 'payments_gateway_payment_id_unique');
            $table->index(['payable_type', 'payable_id'], 'payments_payable_type_payable_id_index');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 100);
            $table->string('type', 80);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id'], 'webhook_events_provider_event_id_unique');
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

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedSmallInteger('qty');
            $table->index(['order_item_id'], 'shipment_items_order_item_id_foreign');
            $table->unique(['shipment_id', 'order_item_id'], 'shipment_items_shipment_id_order_item_id_unique');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->string('status', 25);
            $table->string('location', 160)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'occurred_at'], 'shipment_events_shipment_id_occurred_at_index');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('invoice_number', 30);
            $table->timestamp('issued_at');
            $table->string('seller_tax_id', 20)->nullable();
            $table->string('buyer_tax_id', 20)->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('tax_total', 12, 2);
            $table->timestamps();
            $table->unique(['invoice_number'], 'invoices_invoice_number_unique');
            $table->index(['order_id'], 'invoices_order_id_foreign');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('method', 15);
            $table->string('status', 15)->default('pending');
            $table->string('gateway_refund_id', 80)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('bank_account_holder', 120)->nullable();
            $table->text('bank_account_number')->nullable();
            $table->string('bank_ifsc', 15)->nullable();
            $table->string('upi_id', 80)->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id'], 'refunds_coupon_id_foreign');
            $table->index(['initiated_by'], 'refunds_initiated_by_foreign');
            $table->index(['order_id'], 'refunds_order_id_foreign');
            $table->index(['payment_id'], 'refunds_payment_id_foreign');
        });

        // ---- Reward points
        Schema::create('reward_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->integer('available')->default(0);
            $table->integer('pending')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lifetime_redeemed')->default(0);
            $table->timestamps();
            $table->primary(['user_id']);
        });

        Schema::create('reward_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 20);
            $table->string('status', 15)->default('pending');
            $table->integer('coins');
            $table->unsignedInteger('remaining')->default(0);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['created_by'], 'reward_transactions_created_by_foreign');
            $table->index(['source_type', 'source_id'], 'reward_transactions_source_type_source_id_index');
            $table->index(['user_id', 'status', 'expires_at'], 'reward_transactions_user_id_status_expires_at_index');
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('redeem_transaction_id');
            $table->unsignedBigInteger('earn_transaction_id');
            $table->unsignedInteger('coins');
            $table->index(['earn_transaction_id'], 'reward_redemptions_earn_transaction_id_foreign');
            $table->index(['redeem_transaction_id'], 'reward_redemptions_redeem_transaction_id_foreign');
        });

        // ---- Content (CMS)
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 30);
            $table->string('title', 160)->nullable();
            $table->string('link_type', 15)->default('url');
            $table->string('link_target', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['placement', 'is_active', 'sort_order'], 'banners_placement_is_active_sort_order_index');
        });

        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('type', 30);
            $table->string('title', 160)->nullable();
            $table->unsignedBigInteger('collection_id')->nullable();
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['collection_id'], 'homepage_sections_collection_id_foreign');
            $table->unique(['key'], 'homepage_sections_key_unique');
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120);
            $table->string('title', 255);
            $table->longText('body')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->unique(['slug'], 'cms_pages_slug_unique');
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 40);
            $table->string('question', 255);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['group_key', 'is_active', 'sort_order'], 'faqs_group_key_is_active_sort_order_index');
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

        Schema::table('user_addresses', function (Blueprint $table) {
            $table->foreign('user_id', 'user_addresses_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('sub_categories', function (Blueprint $table) {
            $table->foreign('category_id', 'sub_categories_category_id_foreign')->references('id')->on('categories')->onDelete('restrict');
        });

        Schema::table('brand_collections', function (Blueprint $table) {
            $table->foreign('brand_id', 'brand_collections_brand_id_foreign')->references('id')->on('brands')->onDelete('cascade');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('brand_id', 'products_brand_id_foreign')->references('id')->on('brands')->onDelete('set null');
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

        Schema::table('collections', function (Blueprint $table) {
            $table->foreign('tag_id', 'collections_tag_id_foreign')->references('id')->on('tags')->onDelete('set null');
        });

        Schema::table('collection_product', function (Blueprint $table) {
            $table->foreign('collection_id', 'collection_product_collection_id_foreign')->references('id')->on('collections')->onDelete('cascade');
            $table->foreign('product_id', 'collection_product_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->foreign('order_item_id', 'product_reviews_order_item_id_foreign')->references('id')->on('order_items')->onDelete('set null');
            $table->foreign('product_id', 'product_reviews_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('user_id', 'product_reviews_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('product_rating_stats', function (Blueprint $table) {
            $table->foreign('product_id', 'product_rating_stats_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('product_questions', function (Blueprint $table) {
            $table->foreign('answered_by', 'product_questions_answered_by_foreign')->references('id')->on('admins')->onDelete('set null');
            $table->foreign('product_id', 'product_questions_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('user_id', 'product_questions_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('product_feedback', function (Blueprint $table) {
            $table->foreign('product_id', 'product_feedback_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('user_id', 'product_feedback_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->foreign('user_id', 'wishlists_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->foreign('product_id', 'wishlist_items_product_id_foreign')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variant_id', 'wishlist_items_variant_id_foreign')->references('id')->on('product_variants')->onDelete('cascade');
            $table->foreign('wishlist_id', 'wishlist_items_wishlist_id_foreign')->references('id')->on('wishlists')->onDelete('cascade');
        });

        Schema::table('wishlist_shares', function (Blueprint $table) {
            $table->foreign('shared_with_user_id', 'wishlist_shares_shared_with_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('wishlist_id', 'wishlist_shares_wishlist_id_foreign')->references('id')->on('wishlists')->onDelete('cascade');
        });

        Schema::table('coupon_targets', function (Blueprint $table) {
            $table->foreign('coupon_id', 'coupon_targets_coupon_id_foreign')->references('id')->on('coupons')->onDelete('cascade');
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreign('coupon_id', 'coupon_usages_coupon_id_foreign')->references('id')->on('coupons')->onDelete('cascade');
            $table->foreign('order_id', 'coupon_usages_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('user_id', 'coupon_usages_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('coupon_id', 'carts_coupon_id_foreign')->references('id')->on('coupons')->onDelete('set null');
            $table->foreign('user_id', 'carts_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreign('cart_id', 'cart_items_cart_id_foreign')->references('id')->on('carts')->onDelete('cascade');
            $table->foreign('variant_id', 'cart_items_variant_id_foreign')->references('id')->on('product_variants')->onDelete('cascade');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('coupon_id', 'orders_coupon_id_foreign')->references('id')->on('coupons')->onDelete('set null');
            $table->foreign('user_id', 'orders_user_id_foreign')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('brand_id', 'order_items_brand_id_foreign')->references('id')->on('brands')->onDelete('set null');
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

        Schema::table('shipment_items', function (Blueprint $table) {
            $table->foreign('order_item_id', 'shipment_items_order_item_id_foreign')->references('id')->on('order_items')->onDelete('cascade');
            $table->foreign('shipment_id', 'shipment_items_shipment_id_foreign')->references('id')->on('shipments')->onDelete('cascade');
        });

        Schema::table('shipment_events', function (Blueprint $table) {
            $table->foreign('shipment_id', 'shipment_events_shipment_id_foreign')->references('id')->on('shipments')->onDelete('cascade');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('order_id', 'invoices_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->foreign('coupon_id', 'refunds_coupon_id_foreign')->references('id')->on('coupons')->onDelete('set null');
            $table->foreign('initiated_by', 'refunds_initiated_by_foreign')->references('id')->on('admins')->onDelete('set null');
            $table->foreign('order_id', 'refunds_order_id_foreign')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('payment_id', 'refunds_payment_id_foreign')->references('id')->on('payments')->onDelete('set null');
        });

        Schema::table('reward_wallets', function (Blueprint $table) {
            $table->foreign('user_id', 'reward_wallets_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('reward_transactions', function (Blueprint $table) {
            $table->foreign('created_by', 'reward_transactions_created_by_foreign')->references('id')->on('admins')->onDelete('set null');
            $table->foreign('user_id', 'reward_transactions_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->foreign('earn_transaction_id', 'reward_redemptions_earn_transaction_id_foreign')->references('id')->on('reward_transactions')->onDelete('cascade');
            $table->foreign('redeem_transaction_id', 'reward_redemptions_redeem_transaction_id_foreign')->references('id')->on('reward_transactions')->onDelete('cascade');
        });

        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->foreign('collection_id', 'homepage_sections_collection_id_foreign')->references('id')->on('collections')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'faqs',
            'cms_pages',
            'homepage_sections',
            'banners',
            'reward_redemptions',
            'reward_transactions',
            'reward_wallets',
            'refunds',
            'invoices',
            'shipment_events',
            'shipment_items',
            'shipments',
            'webhook_events',
            'payments',
            'order_status_history',
            'order_addresses',
            'order_items',
            'orders',
            'cart_items',
            'carts',
            'coupon_usages',
            'coupon_targets',
            'coupons',
            'wishlist_shares',
            'wishlist_items',
            'wishlists',
            'product_feedback',
            'product_questions',
            'product_rating_stats',
            'product_reviews',
            'collection_product',
            'collections',
            'inventory_movements',
            'product_relations',
            'product_content_tabs',
            'product_images',
            'product_tag',
            'variant_price_tiers',
            'product_variants',
            'products',
            'tags',
            'brand_collections',
            'brands',
            'sub_categories',
            'categories',
            'pincodes',
            'user_addresses',
            'notifications',
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
            'password_reset_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};

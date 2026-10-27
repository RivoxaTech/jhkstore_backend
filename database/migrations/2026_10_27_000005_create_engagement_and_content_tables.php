<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- reviews / questions / feedback ----
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('published');
            $table->boolean('is_verified_purchase')->default(false);
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'status', 'created_at']);
        });
        DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('product_rating_stats', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('avg', 3, 2)->default(0);
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('c1')->default(0);
            $table->unsignedInteger('c2')->default(0);
            $table->unsignedInteger('c3')->default(0);
            $table->unsignedInteger('c4')->default(0);
            $table->unsignedInteger('c5')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });

        Schema::create('product_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('purchased')->nullable();
            $table->unsignedTinyInteger('quality_rating')->nullable();
            $table->unsignedTinyInteger('price_rating')->nullable();
            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        // ---- wishlists ----
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->string('visibility', 10)->default('public');
            $table->char('share_token', 26)->nullable()->unique();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['wishlist_id', 'variant_id']);
            $table->index('product_id');
        });

        Schema::create('wishlist_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_with_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['wishlist_id', 'shared_with_user_id']);
        });

        // ---- rewards & membership ----
        Schema::create('reward_wallets', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->integer('available')->default(0);
            $table->integer('pending')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lifetime_redeemed')->default(0);
            $table->timestamps();
        });

        Schema::create('reward_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 15)->default('pending');
            $table->integer('coins');
            $table->unsignedInteger('remaining')->default(0);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redeem_transaction_id')->constrained('reward_transactions')->cascadeOnDelete();
            $table->foreignId('earn_transaction_id')->constrained('reward_transactions')->cascadeOnDelete();
            $table->unsignedInteger('coins');
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->unsignedTinyInteger('duration_months');
            $table->decimal('price', 8, 2);
            $table->decimal('mrp', 8, 2)->nullable();
            $table->string('saving_label', 20)->nullable();
            $table->decimal('coin_multiplier', 3, 1)->default(2.0);
            $table->decimal('free_shipping_threshold', 10, 2)->default(499);
            $table->decimal('redemption_cap_order_percent', 4, 1)->default(20);
            $table->decimal('redemption_cap_balance_percent', 4, 1)->default(40);
            $table->boolean('priority_processing')->default(true);
            $table->boolean('education_pass')->default(true);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('status', 15)->default('pending');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('price_paid', 8, 2);
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'status', 'ends_at']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 20);
            $table->string('type', 10)->default('user');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('pending');
            $table->foreignId('qualifying_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedInteger('coins_awarded')->default(0);
            $table->timestamps();
            $table->index(['referrer_user_id', 'status']);
        });

        // ---- leads / forms ----
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('type', 25);
            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('phone', 25);
            $table->string('city', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 15)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'status', 'created_at']);
        });

        Schema::create('enquiry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
        });

        // ---- content ----
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 30);
            $table->foreignId('channel_id')->nullable()->constrained('sales_channels')->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->string('link_type', 15)->default('url');
            $table->string('link_target', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['placement', 'channel_id', 'is_active', 'sort_order']);
        });

        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('type', 30);
            $table->string('title', 160)->nullable();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['channel_id', 'key']);
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 40);
            $table->string('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['group_key', 'is_active', 'sort_order']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('mode', 12)->default('in_person');
            $table->string('category', 60)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('venue')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('registration_url', 500)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('phone', 25)->nullable();
            $table->string('status', 15)->default('registered');
            $table->timestamps();
            $table->unique(['event_id', 'email']);
        });

        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10)->unique();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->boolean('is_serviceable')->default(true);
            $table->boolean('cod_available')->default(true);
            $table->unsignedTinyInteger('standard_eta_days')->nullable();
            $table->boolean('instadent_available')->default(false);
            $table->unsignedTinyInteger('instadent_eta_days')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('channel_id')->nullable()->constrained('sales_channels')->nullOnDelete();
            $table->string('audience', 10)->default('all'); // all | member | non_member
            $table->decimal('min_cart_value', 10, 2)->default(0);
            $table->decimal('fee', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 40);
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'site_settings', 'shipping_rules', 'pincodes', 'event_registrations', 'events', 'faqs', 'cms_pages',
            'homepage_sections', 'banners', 'enquiry_notes', 'enquiries', 'referrals', 'user_memberships',
            'membership_plans', 'reward_redemptions', 'reward_transactions', 'reward_wallets', 'wishlist_shares',
            'wishlist_items', 'wishlists', 'product_feedback', 'product_questions', 'product_rating_stats',
            'product_reviews',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // standard | instadent
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_login')->default(false);
            $table->unsignedSmallInteger('delivery_days')->default(5);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_menu')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_menu')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['category_id', 'slug']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->string('color', 9)->nullable();
            $table->string('style', 20)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('sub_categories')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('slug', 280)->unique();
            $table->string('sku', 80)->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('badge_text', 40)->nullable();
            $table->string('hsn_code', 12)->nullable();
            $table->decimal('gst_rate', 4, 2)->default(18);
            $table->string('country_of_origin', 80)->nullable();
            $table->string('warranty_text')->nullable();
            $table->unsignedSmallInteger('min_order_qty')->default(1);
            $table->unsignedSmallInteger('max_order_qty')->default(10);
            $table->boolean('is_returnable')->default(true);
            $table->boolean('is_cod_available')->default(true);
            $table->boolean('is_professional_only')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            // denormalized, maintained by code
            $table->decimal('min_price', 12, 2)->nullable();
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->decimal('rating_avg', 2, 1)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('is_in_stock')->default(false);
            $table->unsignedInteger('popularity_score')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['brand_id', 'status']);
            $table->index(['category_id', 'status', 'min_price']);
            $table->index(['sub_category_id', 'status', 'min_price']);
            $table->index(['status', 'published_at']);
            $table->index(['status', 'popularity_score']);
            $table->fullText(['name', 'short_description']);
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['product_id', 'tag_id']);
            $table->index(['tag_id', 'product_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 80)->unique();
            $table->string('name', 255);
            $table->boolean('is_default')->default(false);
            $table->json('attributes')->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedInteger('reward_points')->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('barcode', 40)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->decimal('width_cm', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->boolean('is_cod_available')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
            $table->index('selling_price');
            $table->index('discount_percent');
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_variant_price CHECK (selling_price <= mrp)');

        Schema::create('variant_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedSmallInteger('min_qty');
            $table->decimal('price', 12, 2);
            $table->timestamps();
            $table->unique(['variant_id', 'min_qty']);
        });

        Schema::create('product_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'channel_id']);
            $table->index(['channel_id', 'is_active', 'product_id']);
        });

        Schema::create('variant_channel_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->integer('stock_qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->decimal('price_override', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['variant_id', 'channel_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('qty_change');
            $table->integer('balance_after');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'channel_id', 'created_at']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('type', 10)->default('image'); // image | video
            $table->string('url', 500)->nullable();       // remote image / video url
            $table->string('alt', 160)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_content_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('tab_key', 30);
            $table->string('format', 10)->default('paragraphs');
            $table->longText('content')->nullable();
            $table->string('manual_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'tab_key']);
        });

        Schema::create('product_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 20)->default('related');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['product_id', 'related_product_id', 'type']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 10)->default('manual');
            $table->foreignId('tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->json('rule')->nullable();
            $table->foreignId('channel_id')->nullable()->constrained('sales_channels')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['collection_id', 'product_id']);
        });

        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 160)->unique();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_searched_at')->nullable();
            $table->boolean('is_suggested')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'search_terms', 'collection_product', 'collections', 'product_relations', 'product_content_tabs',
            'product_images', 'inventory_movements', 'variant_channel_stock', 'product_channels',
            'variant_price_tiers', 'product_variants', 'product_tag', 'products', 'tags',
            'sub_categories', 'categories', 'sales_channels',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

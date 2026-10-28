<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- promotions ----
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->string('discount_type', 10); // flat | percent
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_cart_value', 10, 2)->default(0);
            $table->string('audience', 15)->default('all'); // all | new_users | members | specific
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->foreignId('channel_id')->nullable()->constrained('sales_channels')->nullOnDelete();
            $table->boolean('is_stackable')->default(false);
            $table->boolean('show_in_my_coupons')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 15); // category | sub_category | brand | product
            $table->unsignedBigInteger('target_id');
            $table->index(['coupon_id', 'target_type']);
        });

        // ---- carts ----
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('guest_token', 40)->nullable()->unique();
            $table->foreignId('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->unsignedInteger('coins_to_redeem')->default(0);
            $table->string('status', 15)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'channel_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id']);
        });

        // ---- orders ----
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->string('status', 25)->default('pending_payment');
            $table->string('payment_status', 20)->default('pending');
            $table->string('fulfillment_status', 20)->default('unfulfilled');
            $table->string('payment_method', 20)->nullable();
            $table->decimal('items_subtotal', 12, 2);
            $table->decimal('mrp_total', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedInteger('coins_redeemed')->default(0);
            $table->decimal('coins_discount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('cod_fee', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->unsignedInteger('coins_to_earn')->default(0);
            $table->string('customer_gst_number', 20)->nullable();
            $table->string('customer_note', 500)->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('source', 10)->default('web');
            $table->boolean('membership_applied')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('payment_status');
            $table->index(['channel_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name', 255);
            $table->string('image_url', 500)->nullable();
            $table->decimal('mrp', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('qty');
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 4, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->unsignedInteger('coins_earned')->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('hsn_code', 12)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('cancelled_qty')->default(0);
            $table->unsignedSmallInteger('returned_qty')->default(0);
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // shipping | billing
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('pincode', 10);
            $table->string('country', 60)->default('India');
            $table->string('gst_number', 20)->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'type']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->string('note')->nullable();
            $table->string('changed_by_type', 10)->default('system');
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->boolean('is_customer_visible')->default(true);
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_amount', 10, 2);
            $table->timestamps();
            $table->unique(['coupon_id', 'order_id']);
        });

        // ---- payments (polymorphic: order or membership purchase) ----
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type', 40);
            $table->unsignedBigInteger('payable_id');
            $table->string('method', 20);
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_order_id', 80)->nullable()->unique();
            $table->string('gateway_payment_id', 80)->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->string('status', 20)->default('initiated');
            $table->string('failure_reason')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['payable_type', 'payable_id']);
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
            $table->unique(['provider', 'event_id']);
        });

        // ---- shipping / tracking ----
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('shipment_number', 30)->unique();
            $table->string('carrier', 60)->nullable();
            $table->string('awb_number', 60)->nullable()->index();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 25)->default('created');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->unique(['shipment_id', 'order_item_id']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 25);
            $table->string('location', 160)->nullable();
            $table->string('description')->nullable();
            $table->timestamp('occurred_at');
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 30)->unique();
            $table->timestamp('issued_at');
            $table->string('seller_gstin', 20)->nullable();
            $table->string('buyer_gstin', 20)->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('tax_total', 12, 2);
            $table->timestamps();
        });

        // ---- returns & refunds ----
        Schema::create('return_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('applies_to', 10)->default('both'); // return | replace | both
            $table->boolean('requires_media')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // return | replace
            $table->string('status', 25)->default('requested');
            $table->foreignId('reason_id')->constrained('return_reasons')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('preferred_refund', 15)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('pickup_scheduled_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('qc_notes')->nullable();
            $table->boolean('self_shipped')->default(false);
            $table->decimal('shipping_reimbursement', 8, 2)->nullable();
            $table->boolean('reported_within_window')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->foreignId('reason_id')->nullable()->constrained('return_reasons')->nullOnDelete();
            $table->string('condition', 20)->nullable();
        });

        Schema::create('return_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->string('note')->nullable();
            $table->string('changed_by_type', 10)->default('system');
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained('return_requests')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 15); // original | bank | coupon | coins
            $table->string('status', 15)->default('pending');
            $table->string('gateway_refund_id', 80)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('bank_account_holder', 120)->nullable();
            $table->text('bank_account_number')->nullable(); // encrypted by the model cast
            $table->string('bank_ifsc', 15)->nullable();
            $table->string('upi_id', 80)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'refunds', 'return_status_history', 'return_request_items', 'return_requests', 'return_reasons',
            'invoices', 'shipment_events', 'shipment_items', 'shipments', 'webhook_events', 'payments',
            'coupon_usages', 'order_status_history', 'order_addresses', 'order_items', 'orders',
            'cart_items', 'carts', 'coupon_targets', 'coupons',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

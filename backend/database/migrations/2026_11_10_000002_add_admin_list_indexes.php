<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for what the admin panel asks all day: the order badge and its "version", the product list order, the payment-method filter. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->index(['payable_type', 'proof_status'], 'payments_payable_type_proof_status_index'));
        Schema::table('products', fn (Blueprint $t) => $t->index(['sort_order', 'id'], 'products_sort_order_id_index'));
        Schema::table('orders', function (Blueprint $t) {
            $t->index(['payment_method', 'created_at'], 'orders_payment_method_created_at_index');
            $t->index(['updated_at'], 'orders_updated_at_index');
        });
        Schema::table('wholesale_requests', fn (Blueprint $t) => $t->index(['updated_at'], 'wholesale_requests_updated_at_index'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_payable_type_proof_status_index'));
        Schema::table('products', fn (Blueprint $t) => $t->dropIndex('products_sort_order_id_index'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex('orders_payment_method_created_at_index');
            $t->dropIndex('orders_updated_at_index');
        });
        Schema::table('wholesale_requests', fn (Blueprint $t) => $t->dropIndex('wholesale_requests_updated_at_index'));
    }
};

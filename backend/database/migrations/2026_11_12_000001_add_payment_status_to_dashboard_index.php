<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The dashboard's revenue (money received) also reads the payment status: the covering index of the dashboard gets it too, so it stays an index-only read. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex('orders_created_status_total_index');
            $t->index(['created_at', 'status', 'payment_status', 'grand_total'], 'orders_created_status_pay_total_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex('orders_created_status_pay_total_index');
            $t->index(['created_at', 'status', 'grand_total'], 'orders_created_status_total_index');
        });
    }
};
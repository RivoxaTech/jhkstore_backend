<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the tables that no module uses and that are not part of the plan: memberships, returns, OTP codes, referrals,
 * events and registrations, shipping rules, search terms and enquiries (plus the two referral columns of `users` and
 * the link from `refunds` to a return request). Wishlist, coupons, reviews, CMS, notifications and the payment /
 * shipment / refund / invoice tables stay for the next phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropForeign(['return_request_id']);
            $table->dropColumn('return_request_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by_user_id']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referred_by_user_id', 'referral_code']);
        });

        // children before parents
        foreach ([
            'user_memberships', 'membership_plans',
            'return_request_items', 'return_status_history', 'return_requests', 'return_reasons',
            'otp_codes', 'referrals',
            'event_registrations', 'events',
            'shipping_rules', 'search_terms',
            'enquiry_notes', 'enquiries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // One-way clean-up: these tables were removed from the product.
    }
};

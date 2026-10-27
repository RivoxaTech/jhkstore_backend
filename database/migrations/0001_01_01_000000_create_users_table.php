<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers (storefront users). Admins live in their own table.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 120);
            $table->string('email', 190)->nullable()->unique();
            $table->string('mobile', 20)->nullable()->unique();
            $table->string('whatsapp', 20)->nullable();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('professional_type', 30)->nullable();
            $table->string('speciality', 100)->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('anniversary')->nullable();
            $table->string('council_reg_no', 60)->nullable();
            $table->string('verification_status', 20)->default('unverified')->index();
            $table->string('referral_code', 20)->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

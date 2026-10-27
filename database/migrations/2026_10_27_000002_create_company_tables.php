<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name', 100);
            $table->string('dial_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Dropdown "Company Registered As"
        Schema::create('company_registration_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Dropdown "Vendor Type"
        Schema::create('vendor_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->string('slug', 200)->unique();
            $table->foreignId('registration_type_id')->constrained('company_registration_types')->restrictOnDelete();
            $table->foreignId('vendor_type_id')->constrained('vendor_types')->restrictOnDelete();
            $table->foreignId('origin_country_id')->constrained('countries')->restrictOnDelete();
            $table->string('email', 190);
            $table->string('contact_number', 25);
            $table->string('vat_gst_number', 30)->index();
            // step 2 of the registration form
            $table->string('contact_name', 120);
            $table->string('contact_mobile', 25);
            $table->string('contact_email', 190);
            $table->string('contact_whatsapp', 25)->nullable();
            $table->string('address', 255);
            $table->string('landmark', 160)->nullable();
            $table->string('city', 100);
            $table->string('zip_code', 12);
            $table->text('promotion_support');
            // workflow
            $table->string('status', 20)->default('pending')->index();
            $table->string('source', 20);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->boolean('is_in_house')->default(false);
            $table->softDeletes();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->foreignId('origin_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('brand_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('collection', 20); // top | featured | new | suggested
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['brand_id', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_collections');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('vendor_types');
        Schema::dropIfExists('company_registration_types');
        Schema::dropIfExists('countries');
    }
};

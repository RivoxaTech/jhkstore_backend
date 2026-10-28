<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fast search: B-tree indexes for prefix lookups (product name, brand name, company email) and a FULLTEXT index on company names
 * (products already have FULLTEXT(name, short_description) and unique SKU indexes). Also gives the old hidden "Default" options the name
 * of their product: every product page now lists its options, so an option always needs a real name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->index('name', 'products_name_index'));
        Schema::table('brands', fn (Blueprint $table) => $table->index('name', 'brands_name_index'));
        Schema::table('companies', function (Blueprint $table) {
            $table->index('email', 'companies_email_index');
            $table->fullText('name', 'companies_name_fulltext');
        });

        DB::statement("UPDATE product_variants v JOIN products p ON p.id = v.product_id SET v.name = p.name WHERE v.name = 'Default'");
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_name_index'));
        Schema::table('brands', fn (Blueprint $table) => $table->dropIndex('brands_name_index'));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_email_index');
            $table->dropFullText('companies_name_fulltext');
        });
    }
};

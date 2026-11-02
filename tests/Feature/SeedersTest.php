<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_base_seeder_creates_only_the_two_admins_and_the_default_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Admin::count());
        $this->assertSame(count(Settings::DEFINITIONS), DB::table('settings')->count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_the_demo_seeder_adds_neutral_sample_data_and_can_run_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(12, Product::count());
        $this->assertSame(5, DB::table('categories')->count());
        $this->assertSame(7, DB::table('tags')->where('is_system', true)->count());

        // bulk prices are real offers: from 2 units up and cheaper than the option price
        $tiers = DB::table('variant_price_tiers')->join('product_variants', 'product_variants.id', '=', 'variant_price_tiers.variant_id')
            ->get(['variant_price_tiers.min_qty', 'variant_price_tiers.price', 'product_variants.selling_price']);
        $this->assertGreaterThan(0, $tiers->count());
        foreach ($tiers as $t) {
            $this->assertGreaterThanOrEqual(2, $t->min_qty);
            $this->assertLessThan($t->selling_price, $t->price);
        }

        // the sample catalogue is visible on the storefront
        $this->getJson('/api/v1/store/products?per_page=50')->assertOk()->assertJsonCount(12, 'data');
        $this->getJson('/api/v1/store/categories')->assertOk()->assertJsonCount(5, 'data');

        // nothing in it is specific to a business
        $names = Product::pluck('name')->implode(' ');
        $this->assertDoesNotMatchRegularExpression('/dental|dentalkart|instadent/i', $names);
    }
}

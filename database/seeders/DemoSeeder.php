<?php

namespace Database\Seeders;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL sample data to try the shop out (not part of DatabaseSeeder): neutral categories, brands, tags, products
 * with options and quantity prices, and one demo customer. Safe to run again: everything is matched by slug / SKU / email.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Demo customer: DEMO_CUSTOMER_EMAIL / DEMO_CUSTOMER_PASSWORD (.env, default demo@example.com / demo-password).
 * Products have no pictures: add them in the admin panel.
 */
class DemoSeeder extends Seeder
{
    private const CATEGORIES = [
        ['Electronics', 'electronics', ['Phones', 'Laptops', 'Audio', 'Accessories']],
        ['Home & Kitchen', 'home-kitchen', ['Cookware', 'Appliances', 'Storage']],
        ['Clothing', 'clothing', ['Men', 'Women', 'Kids']],
        ['Books & Stationery', 'books-stationery', ['Books', 'Notebooks']],
        ['Sports & Outdoors', 'sports-outdoors', ['Fitness', 'Camping']],
    ];

    private const BRANDS = ['Acme', 'Globex', 'Initech', 'Umbrella', 'Stark'];

    /** [name, sku, category slug, sub-category name, brand, tags, [options...]] where an option is [name, sku, mrp, price, stock, points, [min_qty => price]] */
    private const PRODUCTS = [
        ['Wireless Headphones', 'DEMO-WH', 'electronics', 'Audio', 'Acme', ['best-seller', 'featured'], [
            ['Black', 'DEMO-WH-BK', 120, 89, 40, 10, [3 => 82]],
            ['White', 'DEMO-WH-WH', 120, 89, 25, 10, [3 => 82]],
        ]],
        ['Bluetooth Speaker', 'DEMO-BS', 'electronics', 'Audio', 'Globex', ['hot-selling'], [
            ['Standard', 'DEMO-BS-1', 60, 45, 60, 5, [5 => 40, 10 => 36]],
        ]],
        ['USB-C Charging Cable', 'DEMO-CC', 'electronics', 'Accessories', 'Initech', ['best-price-zone'], [
            ['1 m', 'DEMO-CC-1', 12, 7, 200, 1, [5 => 6]],
            ['2 m', 'DEMO-CC-2', 15, 9, 150, 1, [5 => 8]],
        ]],
        ['Ultrabook 14"', 'DEMO-UB', 'electronics', 'Laptops', 'Stark', ['featured', 'new-arrival'], [
            ['8 GB / 256 GB', 'DEMO-UB-8', 899, 799, 12, 80, []],
            ['16 GB / 512 GB', 'DEMO-UB-16', 1099, 979, 8, 98, []],
        ]],
        ['Non-stick Frying Pan', 'DEMO-FP', 'home-kitchen', 'Cookware', 'Umbrella', ['best-seller'], [
            ['24 cm', 'DEMO-FP-24', 30, 22, 50, 2, [4 => 20]],
            ['28 cm', 'DEMO-FP-28', 36, 27, 40, 3, [4 => 24]],
        ]],
        ['Electric Kettle 1.7 L', 'DEMO-EK', 'home-kitchen', 'Appliances', 'Acme', ['new-arrival'], [
            ['Standard', 'DEMO-EK-1', 40, 32, 35, 3, []],
        ]],
        ['Storage Box Set', 'DEMO-SB', 'home-kitchen', 'Storage', 'Globex', ['freebie'], [
            ['Set of 3', 'DEMO-SB-3', 25, 18, 80, 2, [3 => 16]],
        ]],
        ['Cotton T-Shirt', 'DEMO-TS', 'clothing', 'Men', 'Initech', ['best-seller', 'hot-selling'], [
            ['Small', 'DEMO-TS-S', 20, 14, 70, 1, [5 => 12]],
            ['Medium', 'DEMO-TS-M', 20, 14, 90, 1, [5 => 12]],
            ['Large', 'DEMO-TS-L', 20, 14, 60, 1, [5 => 12]],
        ]],
        ['Summer Dress', 'DEMO-SD', 'clothing', 'Women', 'Stark', ['new-arrival', 'most-searched'], [
            ['Medium', 'DEMO-SD-M', 55, 39, 30, 4, []],
        ]],
        ['Hardcover Notebook', 'DEMO-NB', 'books-stationery', 'Notebooks', 'Umbrella', ['best-price-zone'], [
            ['A5', 'DEMO-NB-A5', 9, 6, 300, 1, [10 => 5]],
            ['A4', 'DEMO-NB-A4', 12, 8, 250, 1, [10 => 7]],
        ]],
        ['Yoga Mat', 'DEMO-YM', 'sports-outdoors', 'Fitness', 'Acme', ['featured', 'most-searched'], [
            ['6 mm', 'DEMO-YM-6', 35, 24, 45, 2, [3 => 21]],
        ]],
        ['2-Person Camping Tent', 'DEMO-CT', 'sports-outdoors', 'Camping', 'Globex', ['hot-selling'], [
            ['Standard', 'DEMO-CT-1', 150, 119, 15, 12, []],
        ]],
    ];

    public function run(): void
    {
        $this->call(TagSeeder::class);
        $admin = Admin::query()->first() ?? Admin::create(['username' => 'demo', 'name' => 'Demo', 'password' => 'demo-password', 'is_active' => true]);

        $categories = $this->categories();
        $brands = collect(self::BRANDS)->mapWithKeys(function ($name, $i) {
            $brand = Brand::query()->firstOrNew(['name' => $name]);
            if (! $brand->exists) {
                $brand->fill(['is_active' => true, 'sort_order' => $i]);
                $brand->slug = Brand::uniqueSlug($name);
                $brand->save();
            }

            return [$name => $brand];
        });

        foreach (self::PRODUCTS as [$name, $sku, $categorySlug, $subName, $brandName, $tagSlugs, $options]) {
            if (DB::table('products')->where('sku', $sku)->exists()) {
                continue;
            }
            $category = $categories[$categorySlug];
            app(SaveProduct::class)->create([
                'brand_id' => $brands[$brandName]->id,
                'category_id' => $category->id,
                'sub_category_id' => SubCategory::query()->where('category_id', $category->id)->where('name', $subName)->value('id'),
                'name' => $name,
                'sku' => $sku,
                'short_description' => 'Sample product for trying out the shop.',
                'description' => 'This is demo data. Replace it with your own products in the admin panel.',
                'status' => 'active',
                'tag_ids' => Tag::query()->whereIn('slug', $tagSlugs)->pluck('id')->all(),
                'variants' => collect($options)->values()->map(fn ($o, $i) => [
                    'name' => count($options) === 1 ? $name : "$name - {$o[0]}",
                    'sku' => $o[1], 'is_default' => $i === 0, 'mrp' => $o[2], 'selling_price' => $o[3], 'stock' => $o[4], 'reward_points' => $o[5],
                    'price_tiers' => collect($o[6])->map(fn ($price, $qty) => ['min_qty' => $qty, 'price' => $price])->values()->all(),
                ])->all(),
            ], $admin);
        }

        $this->customer();
    }

    /** @return array<string, Category> by slug */
    private function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $i => [$name, $slug, $subs]) {
            $category = Category::query()->withTrashed()->firstOrNew(['slug' => $slug]);
            if (! $category->exists) {
                $category->fill(['name' => $name, 'is_active' => true, 'is_top' => true, 'sort_order' => $i + 1]);
                $category->slug = $slug;
                $category->save();
            }
            foreach ($subs as $j => $subName) {
                $sub = SubCategory::query()->withTrashed()->firstOrNew(['category_id' => $category->id, 'name' => $subName]);
                if (! $sub->exists) {
                    $sub->fill(['name' => $subName, 'is_active' => true, 'sort_order' => $j + 1]);
                    $sub->category_id = $category->id;
                    $sub->slug = SubCategory::uniqueSlug($subName);
                    $sub->save();
                }
            }
            $out[$slug] = $category;
        }

        return $out;
    }

    private function customer(): void
    {
        $email = env('DEMO_CUSTOMER_EMAIL', 'demo@example.com');
        $password = env('DEMO_CUSTOMER_PASSWORD', 'demo-password');
        if (app()->isProduction()) {
            return;   // never create a known login on a live site
        }

        $user = User::query()->firstOrCreate(['email' => $email], [
            'full_name' => 'Demo Customer', 'mobile' => '5550100100', 'password' => $password, 'is_active' => true,
        ]);
        if (! $user->wasRecentlyCreated) {
            return;
        }
        app(SaveAddress::class)->create($user, [
            'label' => 'Home', 'type' => 'home', 'name' => 'Demo Customer', 'phone' => '5550100100',
            'line1' => '12 Example Street', 'area' => 'Downtown', 'city' => 'Springfield', 'state' => 'Sample State', 'postcode' => '12345',
        ]);
        if (app(Settings::class)->rewardsEnabled()) {
            app(RewardLedger::class)->grant($user->id, 500, 'Welcome points (demo data)');
        }
    }
}

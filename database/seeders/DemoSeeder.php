<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL sample data to try the shop out (not part of DatabaseSeeder): neutral categories, tags, products
 * with options and quantity prices. Safe to run again: everything is matched by slug / SKU.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
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

    /** [name, sku, category slug, sub-category name, tags, [options...]] where an option is [name, sku, mrp, price, stock, [min_qty => price]] */
    private const PRODUCTS = [
        ['Wireless Headphones', 'DEMO-WH', 'electronics', 'Audio', ['best-seller', 'featured'], [
            ['Black', 'DEMO-WH-BK', 120, 89, 40, [3 => 82]],
            ['White', 'DEMO-WH-WH', 120, 89, 25, [3 => 82]],
        ]],
        ['Bluetooth Speaker', 'DEMO-BS', 'electronics', 'Audio', ['hot-selling'], [
            ['Standard', 'DEMO-BS-1', 60, 45, 60, [5 => 40, 10 => 36]],
        ]],
        ['USB-C Charging Cable', 'DEMO-CC', 'electronics', 'Accessories', ['best-price-zone'], [
            ['1 m', 'DEMO-CC-1', 12, 7, 200, [5 => 6]],
            ['2 m', 'DEMO-CC-2', 15, 9, 150, [5 => 8]],
        ]],
        ['Ultrabook 14"', 'DEMO-UB', 'electronics', 'Laptops', ['featured', 'new-arrival'], [
            ['8 GB / 256 GB', 'DEMO-UB-8', 899, 799, 12, []],
            ['16 GB / 512 GB', 'DEMO-UB-16', 1099, 979, 8, []],
        ]],
        ['Non-stick Frying Pan', 'DEMO-FP', 'home-kitchen', 'Cookware', ['best-seller'], [
            ['24 cm', 'DEMO-FP-24', 30, 22, 50, [4 => 20]],
            ['28 cm', 'DEMO-FP-28', 36, 27, 40, [4 => 24]],
        ]],
        ['Electric Kettle 1.7 L', 'DEMO-EK', 'home-kitchen', 'Appliances', ['new-arrival'], [
            ['Standard', 'DEMO-EK-1', 40, 32, 35, []],
        ]],
        ['Storage Box Set', 'DEMO-SB', 'home-kitchen', 'Storage', ['freebie'], [
            ['Set of 3', 'DEMO-SB-3', 25, 18, 80, [3 => 16]],
        ]],
        ['Cotton T-Shirt', 'DEMO-TS', 'clothing', 'Men', ['best-seller', 'hot-selling'], [
            ['Small', 'DEMO-TS-S', 20, 14, 70, [5 => 12]],
            ['Medium', 'DEMO-TS-M', 20, 14, 90, [5 => 12]],
            ['Large', 'DEMO-TS-L', 20, 14, 60, [5 => 12]],
        ]],
        ['Summer Dress', 'DEMO-SD', 'clothing', 'Women', ['new-arrival', 'most-searched'], [
            ['Medium', 'DEMO-SD-M', 55, 39, 30, []],
        ]],
        ['Hardcover Notebook', 'DEMO-NB', 'books-stationery', 'Notebooks', ['best-price-zone'], [
            ['A5', 'DEMO-NB-A5', 9, 6, 300, [10 => 5]],
            ['A4', 'DEMO-NB-A4', 12, 8, 250, [10 => 7]],
        ]],
        ['Yoga Mat', 'DEMO-YM', 'sports-outdoors', 'Fitness', ['featured', 'most-searched'], [
            ['6 mm', 'DEMO-YM-6', 35, 24, 45, [3 => 21]],
        ]],
        ['2-Person Camping Tent', 'DEMO-CT', 'sports-outdoors', 'Camping', ['hot-selling'], [
            ['Standard', 'DEMO-CT-1', 150, 119, 15, []],
        ]],
    ];

    public function run(): void
    {
        $this->call(TagSeeder::class);
        $admin = Admin::query()->first() ?? Admin::create(['username' => 'demo', 'name' => 'Demo', 'password' => 'demo-password', 'is_active' => true]);

        $categories = $this->categories();
        foreach (self::PRODUCTS as [$name, $sku, $categorySlug, $subName, $tagSlugs, $options]) {
            if (DB::table('products')->where('sku', $sku)->exists()) {
                continue;
            }
            $category = $categories[$categorySlug];
            app(SaveProduct::class)->create([
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
                    'sku' => $o[1], 'is_default' => $i === 0, 'mrp' => $o[2], 'selling_price' => $o[3], 'stock' => $o[4],
                    'price_tiers' => collect($o[5])->map(fn ($price, $qty) => ['min_qty' => $qty, 'price' => $price])->values()->all(),
                ])->all(),
            ], $admin);
        }

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
}

<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Actions\RefreshProductSummary;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Catalog\Models\VariantChannelStock;
use App\Domain\Catalog\Models\VariantPriceTier;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Domain\Review\Actions\SaveReview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL demo catalogue - not part of DatabaseSeeder. Run it with:
 *   php artisan db:seed --class=ProductDemoSeeder
 *
 * Loads the products of the frontend (frontend/src/data/products.js, exported once to data/demo-products.json) under the
 * in-house company: a brand per brand name, the category by slug, remote image urls in product_images.url, both channels with stock, the first top-level sub-category of its category, 0-3 bulk prices ("offers"), a few products with several options and a few reviews.
 * Tags come from the old `section` / `badge` fields (hot-selling, most-searched, BEST SELLER -> best-seller, HOT SELLER -> hot-selling,
 * POPULAR -> featured). So that every homepage section has something to show, products with 50%+ discount also get
 * best-price-zone and the first four Dentaltech ones get new-arrival. Safe to run again (products are matched by SKU "DEMO-<id>").
 */
class ProductDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([TagSeeder::class, CategorySeeder::class, SalesChannelSeeder::class]);

        $company = Company::query()->where('is_in_house', true)->firstOrFail();
        $rows = json_decode(file_get_contents(__DIR__.'/data/demo-products.json'), true, 512, JSON_THROW_ON_ERROR);
        $tags = Tag::query()->pluck('id', 'slug');
        $channels = SalesChannel::idsByCode();
        $summary = new RefreshProductSummary();
        $newArrivals = 0;

        foreach ($rows as $i => $row) {
            $sku = 'DEMO-'.$row['id'];
            if (Product::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }

            DB::transaction(function () use ($row, $sku, $i, $company, $tags, $channels, $summary, &$newArrivals) {
                $brand = Brand::query()->where('company_id', $company->id)->where('name', $row['brand'])->first();
                if (! $brand) {
                    $brand = new Brand(['company_id' => $company->id, 'name' => $row['brand'], 'is_active' => true]);
                    $brand->slug = Brand::uniqueSlug($row['brand']);
                    $brand->save();
                }
                $category = Category::query()->where('slug', $row['category'])->firstOrFail();

                $product = new Product([
                    'company_id' => $company->id,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => $row['name'],
                    'sku' => $sku,
                    'short_description' => $row['short_description'],
                    'description' => $row['short_description'],
                    'badge_text' => $row['badge'],
                ]);
                $product->sub_category_id = SubCategory::query()->where('category_id', $category->id)->whereNull('parent_id')->orderBy('id')->value('id');
                $product->slug = Product::uniqueSlug($row['name']);
                $product->status = ProductStatus::Active;
                $product->published_at = now()->subMinutes($i);
                $product->popularity_score = (int) $row['reviews'] * 10;
                $product->rating_avg = $row['rating'];
                $product->rating_count = $row['reviews'];
                $product->save();

                // one option from price / mrp, or the options listed under "variants" (a product with several options)
                $options = $row['variants'] ?? [['name' => $row['name'], 'price' => $row['price'], 'mrp' => $row['mrp'], 'coins' => $row['coins']]];
                foreach ($options as $n => $option) {
                    $variant = new ProductVariant([
                        'name' => $option['name'], 'sku' => count($options) > 1 ? $sku.'-'.($n + 1) : $sku, 'mrp' => $option['mrp'], 'selling_price' => $option['price'],
                        'reward_points' => $option['coins'], 'is_active' => true, 'sort_order' => $n,
                    ]);
                    $variant->discount_percent = ProductVariant::discountFor($option['mrp'], $option['price']);
                    $variant->product_id = $product->id;
                    $variant->is_default = $n === 0;
                    $variant->save();

                    // "offers": bulk prices "Buy 2+ / 5+ / 10+ for ... each" (0-3 of them)
                    foreach (array_slice([[2, 0.976], [5, 0.952], [10, 0.905]], 0, (int) ($row['offers'] ?? 0)) as [$qty, $factor]) {
                        VariantPriceTier::create(['variant_id' => $variant->id, 'min_qty' => $qty, 'price' => round($option['price'] * $factor)]);
                    }

                    foreach (['standard' => 50, 'instadent' => 20] as $code => $qty) {
                        $qty = $row['in_stock'] ? $qty : 0;
                        VariantChannelStock::create(['variant_id' => $variant->id, 'channel_id' => $channels[$code], 'stock_qty' => $qty, 'is_active' => true]);
                        if ($qty > 0) {
                            InventoryMovement::create([
                                'variant_id' => $variant->id, 'channel_id' => $channels[$code], 'type' => 'initial',
                                'qty_change' => $qty, 'balance_after' => $qty, 'note' => 'Demo data',
                            ]);
                        }
                    }
                }
                foreach ($channels as $channelId) {
                    $product->channels()->attach($channelId, ['is_active' => true]);
                }

                foreach ($row['images'] as $n => $url) {
                    ProductImage::create(['product_id' => $product->id, 'url' => $url, 'sort_order' => $n, 'is_primary' => $n === 0]);
                }

                $slugs = $this->tagSlugs($row, $newArrivals < 4 && $row['section'] === 'dentaltech');
                if (in_array('new-arrival', $slugs, true)) {
                    $newArrivals++;
                }
                $product->tags()->sync(collect($slugs)->map(fn ($s) => $tags[$s])->all());

                $summary->handle($product);
            });
        }

        $this->reviews();
    }

    /** A few reviews (3 reviewers, password "test") so the review list and the rating summary have something to show. */
    private function reviews(): void
    {
        $reviews = [
            ['DEMO-101', [[5, 'Works well', 'Good quality, delivered on time.'], [4, 'Value for money', 'Shade matching is good.'], [5, 'Recommended', null]]],
            ['DEMO-401', [[5, 'Perfect fit', 'Fits the NP implant exactly.'], [4, null, 'Good finish.'], [5, null, null]]],
            ['DEMO-402', [[4, 'Handy', 'Three lengths cover every case.'], [5, null, 'Sturdy drivers.']]],
            ['DEMO-403', [[5, 'Clean packing', 'Pack of ten, sterile and clean.']]],
        ];
        $users = collect([1, 2, 3])->map(fn ($n) => User::firstOrCreate(
            ['email' => "reviewer$n@example.test"],
            ['full_name' => ['Asha Verma', 'Imran Khan', 'Sara Ahmed'][$n - 1], 'mobile' => '9000000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'password' => 'test', 'professional_type' => 'dentist', 'is_active' => true],
        ));

        foreach ($reviews as [$sku, $list]) {
            $product = Product::query()->where('sku', $sku)->first();
            foreach ($list as $k => [$rating, $title, $body]) {
                if ($product) {
                    app(SaveReview::class)->handle($product, $users[$k], ['rating' => $rating, 'title' => $title, 'body' => $body]);
                }
            }
        }
    }

    /** @return list<string> */
    private function tagSlugs(array $row, bool $newArrival): array
    {
        $slugs = [];
        if (in_array($row['section'], ['hot-selling', 'most-searched'], true)) {
            $slugs[] = $row['section'];
        }
        $slugs[] = match (strtoupper((string) $row['badge'])) {
            'BEST SELLER' => 'best-seller',
            'HOT SELLER' => 'hot-selling',
            'POPULAR' => 'featured',
            default => null,
        };
        if ($row['mrp'] > 0 && ($row['mrp'] - $row['price']) / $row['mrp'] >= 0.5) {
            $slugs[] = 'best-price-zone';
        }
        if ($newArrival) {
            $slugs[] = 'new-arrival';
        }

        return array_values(array_unique(array_filter($slugs)));
    }
}

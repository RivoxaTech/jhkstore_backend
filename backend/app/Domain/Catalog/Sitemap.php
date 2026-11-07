<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the storefront shows, as slugs: what the static build prerenders and what sitemap.xml lists.
 * Only what customers may see (the same rules as the catalogue endpoints: `Product::scopeStorefront`, active categories and sub-categories),
 * never a price. Cached 5 minutes and dropped on every admin write that touches the storefront (TrackStorefrontChanges).
 * The number of queries does not depend on the number of products (the products are read in chunks of CHUNK; one chunk = 4 queries).
 */
class Sitemap
{
    public const KEY = 'store.sitemap';

    public const TTL = 300;

    public const CHUNK = 500;

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** @return array{products: list<array>, categories: list<array>, sub_categories: list<array>} */
    public static function build(): array
    {
        return Cache::remember(self::KEY, self::TTL, fn () => self::query());
    }

    private static function query(): array
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'updated_at']);
        $slugOf = $categories->pluck('slug', 'id');

        $subs = SubCategory::query()->whereIn('category_id', $categories->pluck('id'))->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'category_id', 'slug', 'updated_at']);

        $products = [];
        Product::query()->storefront()
            ->select('products.id', 'products.slug', 'products.category_id', 'products.updated_at')
            ->selectSub(DB::table('product_variants as lv')->selectRaw('max(lv.updated_at)')->whereColumn('lv.product_id', 'products.id'), 'variants_updated_at')
            ->with(['media', 'remoteImages'])
            ->orderBy('products.id')
            ->chunk(self::CHUNK, function ($chunk) use (&$products, $slugOf) {
                foreach ($chunk as $product) {
                    $latest = max(array_filter([$product->updated_at, $product->variants_updated_at ? \Illuminate\Support\Carbon::parse($product->variants_updated_at) : null]));
                    $products[] = [
                        'slug' => $product->slug,
                        'category_slug' => $slugOf[$product->category_id] ?? null,
                        'updated_at' => $latest->toIso8601String(),
                        'image_url' => ProductImages::primary($product)['image_url'],
                    ];
                }
            });

        return [
            'products' => $products,
            'categories' => $categories->map(fn ($c) => ['slug' => $c->slug, 'updated_at' => $c->updated_at->toIso8601String()])->values()->all(),
            'sub_categories' => $subs->map(fn ($s) => ['slug' => $s->slug, 'category_slug' => $slugOf[$s->category_id] ?? null, 'updated_at' => $s->updated_at->toIso8601String()])->values()->all(),
        ];
    }
}

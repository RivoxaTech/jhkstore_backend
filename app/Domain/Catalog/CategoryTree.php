<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Http\Resources\StoreCategoryResource;
use App\Http\Resources\StoreSubCategoryResource;
use Illuminate\Support\Facades\Cache;

/**
 * The customer-facing category tree: active categories, their active sub-categories, and the active children
 * (sub-sub-categories) of those. Built once, cached for 10 minutes, and cleared on every admin write.
 * `?top=1` is filtered from the same cached copy.
 *
 * Output: category { ..., sub_categories: [ sub { ..., children: [ child {...} ] } ] }
 */
class CategoryTree
{
    public const KEY = 'store.categories.tree';
    public const TTL = 600;

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * @param  bool  $topOnly  only the top categories (home screen)
     * @return list<array> the shape returned by GET /store/categories
     */
    public static function storefront(bool $topOnly = false): array
    {
        $out = [];
        foreach (self::cached() as $node) {
            if ($topOnly && ! $node['top']) {
                continue;
            }
            $out[] = self::render($node);
        }

        return $out;
    }

    public static function find(string $slug): ?array
    {
        foreach (self::cached() as $node) {
            if ($node['data']['slug'] === $slug) {
                return self::render($node);
            }
        }

        return null;
    }

    private static function render(array $category): array
    {
        $subs = [];
        foreach ($category['subs'] as $sub) {
            $subs[] = $sub['data'] + ['children' => array_column($sub['children'], 'data')];
        }

        return $category['data'] + ['sub_categories' => $subs];
    }

    private static function cached(): array
    {
        return Cache::remember(self::KEY, self::TTL, fn () => self::build());
    }

    private static function build(): array
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with(['media', 'subCategories' => fn ($q) => $q->where('is_active', true)
                ->with(['media', 'children' => fn ($c) => $c->where('is_active', true)->with('media')])])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return $categories->map(fn (Category $category) => [
            'top' => $category->is_top,
            'data' => (new StoreCategoryResource($category))->resolve(),
            'subs' => $category->subCategories->map(fn (SubCategory $sub) => [
                'data' => (new StoreSubCategoryResource($sub))->resolve(),
                'children' => $sub->children->map(fn (SubCategory $child) => [
                    'data' => (new StoreSubCategoryResource($child))->resolve(),
                ])->all(),
            ])->all(),
        ])->all();
    }
}

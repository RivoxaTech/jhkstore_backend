<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Support\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer-site product queries: filtered/sorted list, detail by slug and related products.
 * Everything a card needs is eager loaded in a fixed number of queries (no N+1), and only storefront-visible products
 * are ever returned (see Product::scopeStorefront).
 */
class ProductListing
{
    public const SORTS = ['popularity', 'newest', 'price_asc', 'price_desc', 'discount'];

    /** Relations a product card needs. */
    public static function cardRelations(): array
    {
        return [
            'category:id,name,slug',
            'tags' => fn ($q) => $q->where('tags.is_active', true),
            'media', 'remoteImages',
            'variants' => fn ($q) => $q->where('is_active', true),
            'variants.priceTiers',
        ];
    }

    /**
     * @param  array{tag?:string,category?:string,sub_category?:string,q?:string,min_price?:numeric,max_price?:numeric,in_stock?:bool,sort?:string}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Product::query()->storefront()->with(self::cardRelations());

        $this->filter($query, $filters);
        $this->sort($query, $filters['sort'] ?? 'popularity');

        return $query->paginate($perPage)->withQueryString();
    }

    /** The product page: the card relations plus content tabs and the sub-category; null-safe 404 when not visible. */
    public function find(string $slug): Product
    {
        $product = Product::query()->storefront()
            ->with([...self::cardRelations(), 'subCategory:id,name,slug', 'contentTabs'])
            ->where('products.slug', $slug)
            ->firstOrFail();

        return $product->setRelation('related', $this->related($product));
    }

    /** Hand-picked related products if the admin set any, otherwise popular products of the same category. */
    public function related(Product $product, int $limit = 10): Collection
    {
        $picked = DB::table('product_relations')->where('product_id', $product->id)->where('type', 'related')
            ->orderBy('sort_order')->limit($limit)->pluck('related_product_id');

        $query = Product::query()->storefront()->with(self::cardRelations())->whereKeyNot($product->id);

        if ($picked->isNotEmpty()) {
            return $query->whereIn('products.id', $picked)->get()->sortBy(fn ($p) => $picked->search($p->id))->values();
        }

        return $query->where('products.category_id', $product->category_id)
            ->orderByDesc('products.popularity_score')->orderByDesc('products.id')->limit($limit)->get();
    }

    private function filter(Builder $query, array $f): void
    {
        if (! empty($f['tag'])) {
            $query->withTagSlug($f['tag']);
        }
        if (! empty($f['category'])) {
            $query->whereIn('products.category_id', Category::query()->where('slug', $f['category'])->select('id'));
        }
        if (! empty($f['sub_category'])) {
            // narrow by ?category= when it is given (the same slug may exist in another category)
            $subs = SubCategory::query()->where('slug', $f['sub_category'])
                ->when(! empty($f['category']), fn ($q) => $q->whereIn('category_id', Category::query()->where('slug', $f['category'])->select('id')))
                ->pluck('id');
            $query->whereIn('products.sub_category_id', $subs);
        }
        if (! empty($f['q'])) {
            ProductSearch::apply($query, $f['q']);
        }
        if (isset($f['min_price'])) {
            $query->where('products.min_price', '>=', $f['min_price']);
        }
        if (isset($f['max_price'])) {
            $query->where('products.min_price', '<=', $f['max_price']);
        }
        if (! empty($f['in_stock'])) {
            $query->inStock();
        }
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('products.published_at'),
            'price_asc' => $query->orderBy('products.min_price'),
            'price_desc' => $query->orderByDesc('products.min_price'),
            'discount' => $query->orderByDesc('products.max_discount_percent'),
            default => $query->orderByDesc('products.popularity_score'),
        };
        $query->orderByDesc('products.id'); // stable order for equal values
    }
}

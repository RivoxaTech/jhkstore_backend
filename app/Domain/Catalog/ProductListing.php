<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Brand;
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
    public const SORTS = ['popularity', 'newest', 'price_asc', 'price_desc', 'discount', 'rating'];

    /** Relations a product card needs; the variants' stock rows are limited to the requested channel. */
    public static function cardRelations(SalesChannel $channel): array
    {
        return [
            'brand.media',
            'category:id,name,slug',
            'tags' => fn ($q) => $q->where('tags.is_active', true),
            'media', 'remoteImages',
            'variants' => fn ($q) => $q->where('is_active', true),
            'variants.priceTiers',
            'variants.stocks' => fn ($q) => $q->where('channel_id', $channel->id)->where('is_active', true),
        ];
    }

    /**
     * @param  array{tag?:string,category?:string,sub_category?:string,brand?:string,q?:string,min_price?:numeric,max_price?:numeric,in_stock?:bool,rating?:int,sort?:string}  $filters
     */
    public function paginate(array $filters, SalesChannel $channel, int $perPage): LengthAwarePaginator
    {
        $query = Product::query()->storefront($channel->code)->with(self::cardRelations($channel));

        $this->filter($query, $filters, $channel);
        $this->sort($query, $filters['sort'] ?? 'popularity');

        return $query->paginate($perPage)->withQueryString();
    }

    /** The product page: the card relations plus content tabs and the sub-category; null-safe 404 when not visible. */
    public function find(string $slug, SalesChannel $channel): Product
    {
        $product = Product::query()->storefront($channel->code)
            ->with([...self::cardRelations($channel), 'subCategory:id,name,slug', 'contentTabs'])
            ->where('products.slug', $slug)
            ->firstOrFail();

        return $product->setRelation('related', $this->related($product, $channel));
    }

    /** Hand-picked related products if the admin set any, otherwise popular products of the same category. */
    public function related(Product $product, SalesChannel $channel, int $limit = 10): Collection
    {
        $picked = DB::table('product_relations')->where('product_id', $product->id)->where('type', 'related')
            ->orderBy('sort_order')->limit($limit)->pluck('related_product_id');

        $query = Product::query()->storefront($channel->code)->with(self::cardRelations($channel))->whereKeyNot($product->id);

        if ($picked->isNotEmpty()) {
            return $query->whereIn('products.id', $picked)->get()->sortBy(fn ($p) => $picked->search($p->id))->values();
        }

        return $query->where('products.category_id', $product->category_id)
            ->orderByDesc('products.popularity_score')->orderByDesc('products.id')->limit($limit)->get();
    }

    private function filter(Builder $query, array $f, SalesChannel $channel): void
    {
        if (! empty($f['tag'])) {
            $query->withTagSlug($f['tag']);
        }
        if (! empty($f['category'])) {
            $query->whereIn('products.category_id', Category::query()->where('slug', $f['category'])->select('id'));
        }
        if (! empty($f['sub_category'])) {
            // slugs are unique per category only, so narrow by ?category= when it is given; a parent also lists its children
            $subs = SubCategory::query()->where('slug', $f['sub_category'])
                ->when(! empty($f['category']), fn ($q) => $q->whereIn('category_id', Category::query()->where('slug', $f['category'])->select('id')))
                ->pluck('id');
            $query->whereIn('products.sub_category_id', SubCategory::query()->whereIn('parent_id', $subs)->pluck('id')->merge($subs));
        }
        if (! empty($f['brand'])) {
            $query->whereIn('products.brand_id', Brand::query()->where('slug', $f['brand'])->select('id'));
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
            $query->inStockOn($channel->id);
        }
        if (! empty($f['rating'])) {
            $query->where('products.rating_avg', '>=', $f['rating']);
        }
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('products.published_at'),
            'price_asc' => $query->orderBy('products.min_price'),
            'price_desc' => $query->orderByDesc('products.min_price'),
            'discount' => $query->orderByDesc('products.max_discount_percent'),
            'rating' => $query->orderByDesc('products.rating_avg')->orderByDesc('products.rating_count'),
            default => $query->orderByDesc('products.popularity_score')->orderByDesc('products.rating_count'),
        };
        $query->orderByDesc('products.id'); // stable order for equal values
    }
}

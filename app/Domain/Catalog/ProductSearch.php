<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Support\FullTextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product search that stays fast with a big catalogue (every branch uses an index):
 *  - words of the name / short description -> FULLTEXT index (each typed word matches as a word start: "comp" finds "Composite")
 *  - SKU that starts with the text -> unique index on products.sku (and, for the admin, product_variants.sku)
 *  - brand whose name starts with the text -> index on brands.name
 * A search with a word shorter than 3 letters (or only stop words) cannot use FULLTEXT and falls back to LIKE on the name / description.
 */
class ProductSearch
{
    public static function apply(Builder $query, string $term, bool $includeVariantSku = false): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = FullTextSearch::like($term);
        $boolean = FullTextSearch::booleanQuery($term);

        $query->where(function ($w) use ($term, $like, $boolean, $includeVariantSku) {
            if ($boolean) {
                $w->whereRaw('MATCH(products.name, products.short_description) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
            } else {
                $w->where('products.name', 'like', "%$like%")->orWhere('products.short_description', 'like', "%$like%");
            }
            $w->orWhere('products.sku', 'like', "$like%")
                ->orWhereIn('products.brand_id', Brand::query()->where('name', 'like', "$like%")->select('id'));
            if ($includeVariantSku) {
                $w->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')->where('product_variants.sku', 'like', "$like%"));
            }
        });
    }
}

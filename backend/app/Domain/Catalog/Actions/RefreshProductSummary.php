<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the denormalized columns of `products` in sync, so listing pages never aggregate:
 * min_price, max_discount_percent (from the active variants) and is_in_stock (any active variant with a sellable unit).
 * Call it after every change of variants or stock.
 */
class RefreshProductSummary
{
    public function handle(Product $product): Product
    {
        $row = DB::table('product_variants')
            ->where('product_id', $product->id)->where('is_active', true)
            ->selectRaw('MIN(selling_price) as min_price, MAX(discount_percent) as max_discount')
            ->first();

        $inStock = DB::table('product_variants')
            ->where('product_id', $product->id)->where('is_active', true)
            ->whereRaw('stock_qty - reserved_qty > 0')
            ->exists();

        $product->forceFill([
            'min_price' => $row->min_price,
            'max_discount_percent' => $row->max_discount ?? 0,
            'is_in_stock' => $inStock,
        ])->saveQuietly();

        return $product;
    }
}

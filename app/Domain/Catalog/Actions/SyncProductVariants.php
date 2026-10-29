<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\VariantChannelStock;
use App\Domain\Catalog\Models\VariantPriceTier;
use Illuminate\Support\Facades\DB;

/**
 * Makes the variants of a product match the submitted list: creates new ones, updates the ones that carry an `id`,
 * and removes the rest (a variant that already sits in an order is switched off instead of deleted).
 * Price, MRP, points and the automatic discount live here; so does the quantity per sales channel (with a movement log).
 */
class SyncProductVariants
{
    public function handle(Product $product, array $rows, Admin $admin): void
    {
        $existing = ProductVariant::query()->where('product_id', $product->id)->get()->keyBy('id');
        $single = count($rows) === 1;
        $kept = [];
        $default = null;
        $firstActive = null;

        foreach (array_values($rows) as $i => $row) {
            $variant = isset($row['id']) ? $existing[$row['id']] : new ProductVariant();
            $variant->fill([
                'name' => $row['name'] ?? ($variant->name ?: $product->name),
                'sku' => $row['sku'] ?? ($single ? $product->sku : $variant->sku),
                'mrp' => $row['mrp'],
                'selling_price' => $row['selling_price'],
                'reward_points' => $row['reward_points'] ?? 0,
                'expiry_date' => $row['expiry_date'] ?? null,
                'barcode' => $row['barcode'] ?? null,
                'weight_grams' => $row['weight_grams'] ?? null,
                'is_active' => $row['is_active'] ?? true,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
            $variant->discount_percent = ProductVariant::discountFor($row['mrp'], $row['selling_price']);
            $variant->product_id = $product->id;
            $variant->is_default = false; // fixed below: exactly one default
            $variant->save();

            $this->syncStock($variant, $row['stock'] ?? [], $row['stock_was'] ?? [], $admin);
            if (array_key_exists('price_tiers', $row)) {
                $this->syncTiers($variant, $row['price_tiers'] ?? []);
            }
            $kept[] = $variant->id;
            // the default option (preselected on the product page) must be an active one
            if ($variant->is_active) {
                $firstActive ??= $variant->id;
                if (! empty($row['is_default']) && $default === null) {
                    $default = $variant->id;
                }
            }
        }

        ProductVariant::query()->whereKey($default ?? $firstActive ?? $kept[0])->update(['is_default' => true]);

        foreach ($existing->except($kept) as $old) {
            $inOrder = DB::table('order_items')->where('variant_id', $old->id)->exists();
            if ($inOrder) {
                $old->update(['is_active' => false, 'is_default' => false]);

                continue;
            }
            // the pictures of a removed option go with it
            $product->media()->where('collection_name', 'images')->get()->filter(fn ($m) => (int) $m->getCustomProperty('variant_id') === $old->id)->each->delete();
            $old->delete();
        }
    }

    /** The quantity prices are replaced as a whole. @param  list<array{min_qty:int,price:numeric}>  $tiers */
    private function syncTiers(ProductVariant $variant, array $tiers): void
    {
        VariantPriceTier::query()->where('variant_id', $variant->id)->delete();
        foreach ($tiers as $tier) {
            VariantPriceTier::create(['variant_id' => $variant->id, 'min_qty' => $tier['min_qty'], 'price' => $tier['price']]);
        }
    }

    /**
     * @param  array<string,int>  $stock  channel code => the quantity the admin typed
     * @param  array<string,int>  $was  channel code => the quantity the admin SAW when the form was opened (optional)
     *
     * With `stock_was` the edit is applied as a CHANGE (typed - seen) on top of the live quantity, under the row lock: orders placed while the form
     * was open are never given back, and a form whose stock box was not touched changes nothing. Without it (API clients, new options) the typed number is absolute.
     */
    private function syncStock(ProductVariant $variant, array $stock, array $was, Admin $admin): void
    {
        $channels = SalesChannel::idsByCode();

        foreach ($stock as $code => $qty) {
            if (! isset($channels[$code])) {
                continue;
            }
            $qty = (int) $qty;
            $row = VariantChannelStock::query()->where('variant_id', $variant->id)->where('channel_id', $channels[$code])->lockForUpdate()->first();
            $before = $row?->stock_qty ?? 0;
            if ($row && isset($was[$code])) {
                $qty = $qty === (int) $was[$code] ? $before : max(0, $before + $qty - (int) $was[$code]);
            }

            $row ??= new VariantChannelStock(['variant_id' => $variant->id, 'channel_id' => $channels[$code], 'is_active' => true]);
            $row->stock_qty = $qty;
            $row->save();

            if ($qty !== $before) {
                InventoryMovement::create([
                    'variant_id' => $variant->id,
                    'channel_id' => $channels[$code],
                    'type' => $row->wasRecentlyCreated ? 'initial' : 'adjustment',
                    'qty_change' => $qty - $before,
                    'balance_after' => $qty,
                    'admin_id' => $admin->id,
                    'note' => 'Set in the admin panel',
                ]);
            }
        }
    }
}

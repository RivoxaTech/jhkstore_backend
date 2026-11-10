<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContentTab;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates / updates a product with everything it owns (variants with their stock, tags, content tabs)
 * in one transaction, then refreshes the denormalized columns. Images are handled by ManageProductImages.
 *
 * Parts that are missing from `$data` are left as they are on update (on create: no tags).
 */
class SaveProduct
{
    public function __construct(private SyncProductVariants $variants, private RefreshProductSummary $summary) {}

    public function create(array $data, Admin $admin): Product
    {
        return DB::transaction(function () use ($data, $admin) {
            $product = new Product($this->columns($data));
            $product->slug = $data['slug'] ?? Product::uniqueSlug($data['name']);
            $product->status = $data['status'] ?? ProductStatus::Draft->value;
            $this->stampPublished($product);
            $product->save();

            $this->saveParts($product, $data, $admin);
            Audit::log($admin, 'product.created', $product);

            return $product;
        });
    }

    public function update(Product $product, array $data, Admin $admin): Product
    {
        return DB::transaction(function () use ($product, $data, $admin) {
            $product->fill($this->columns($data));
            if (isset($data['slug'])) {
                $product->slug = $data['slug']; // the slug is stable: it only changes when the admin sets it
            }
            if (isset($data['status'])) {
                $product->status = $data['status'];
            }
            $this->stampPublished($product);
            $product->save();
            $changes = $product->getChanges();

            $this->saveParts($product, $data, $admin);
            Audit::log($admin, 'product.updated', $product, $changes);

            return $product;
        });
    }

    public function setStatus(Product $product, string $status, Admin $admin): Product
    {
        $product->status = $status;
        $this->stampPublished($product);
        $product->save();
        Audit::log($admin, 'product.status_changed', $product, ['status' => $status]);

        return $product;
    }

    /** The "Sold out" switch: the product stays visible but cannot be ordered. */
    public function setSoldOut(Product $product, bool $value, Admin $admin): Product
    {
        $product->forceFill(['is_sold_out' => $value])->save();
        Audit::log($admin, 'product.sold_out_changed', $product, ['is_sold_out' => $value]);

        return $product;
    }

    public function setFeatured(Product $product, bool $value, Admin $admin): Product
    {
        $product->forceFill(['is_featured' => $value])->save();
        Audit::log($admin, 'product.featured_changed', $product, ['is_featured' => $value]);

        return $product;
    }

    /** @param  list<int>  $tagIds */
    public function setTags(Product $product, array $tagIds, Admin $admin): Product
    {
        $product->tags()->sync($tagIds);
        Audit::log($admin, 'product.tags_changed', $product, ['tag_ids' => $tagIds]);

        return $product;
    }

    public function delete(Product $product, Admin $admin): void
    {
        $product->delete();
        Audit::log($admin, 'product.deleted', $product);
    }

    /**
     * Drag and drop on the Products screen: `$ids` are the products of ONE page in their new order. They take the places the same products
     * had in the whole list (so a page that is not shown does not move), then every product gets its position 1..n.
     *
     * @param  list<int>  $ids
     */
    public function reorder(array $ids, Admin $admin): void
    {
        DB::transaction(function () use ($ids, $admin) {
            $all = Product::query()->orderBy('sort_order')->orderByDesc('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $slots = [];
            foreach ($all as $position => $id) {
                if (in_array($id, $ids, true)) {
                    $slots[] = $position;
                }
            }
            foreach (array_values($ids) as $i => $id) {
                if (isset($slots[$i])) {
                    $all[$slots[$i]] = (int) $id;
                }
            }
            foreach (array_chunk($all, 200, true) as $chunk) {
                $cases = '';
                foreach ($chunk as $position => $id) {
                    $cases .= ' WHEN '.(int) $id.' THEN '.($position + 1);
                }
                DB::table('products')->whereIn('id', array_values($chunk))->update(['sort_order' => DB::raw('CASE id'.$cases.' END')]);
            }
            Audit::log($admin, 'product.reordered', null, ['ids' => array_values($ids)]);
        });
    }

    private function saveParts(Product $product, array $data, Admin $admin): void
    {
        if (isset($data['variants'])) {
            $this->variants->handle($product, $data['variants'], $admin);
        }
        if (isset($data['tag_ids'])) {
            $product->tags()->sync($data['tag_ids']);
        }
        if (isset($data['content_tabs'])) {
            $this->saveTabs($product, $data['content_tabs']);
        }
        if (isset($data['related_ids'])) {
            $product->related()->sync(collect($data['related_ids'])->values()->mapWithKeys(
                fn ($id, $i) => [$id => ['type' => 'related', 'sort_order' => $i]]
            )->all());
        }

        $this->summary->handle($product);
    }

    /** The tabs are replaced as a whole; empty ones are dropped. */
    private function saveTabs(Product $product, array $tabs): void
    {
        $product->contentTabs()->delete();
        $order = array_flip(array_keys(ProductContentTab::TABS));

        foreach ($tabs as $tab) {
            if (blank($tab['content'] ?? null) && blank($tab['manual_url'] ?? null)) {
                continue;
            }
            $product->contentTabs()->create([
                'tab_key' => $tab['tab_key'],
                'format' => $tab['format'] ?? 'paragraphs',
                'content' => $tab['content'] ?? null,
                'manual_url' => $tab['manual_url'] ?? null,
                'sort_order' => $order[$tab['tab_key']] ?? 99,
            ]);
        }
    }

    private function stampPublished(Product $product): void
    {
        if ($product->status === ProductStatus::Active && $product->published_at === null) {
            $product->published_at = now();
        }
    }

    /** Only the product's own columns (the rest of the payload is children). */
    private function columns(array $data): array
    {
        return Arr::except($data, ['slug', 'status', 'variants', 'tag_ids', 'content_tabs', 'related_ids']);
    }
}

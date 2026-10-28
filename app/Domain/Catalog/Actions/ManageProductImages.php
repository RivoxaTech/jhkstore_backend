<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\ProductImages;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload, delete and reorder the pictures of a product. There are two kinds of "gallery":
 * the product's own pictures (the general ones, for all options), and the pictures of one option (variant) - stored as media with
 * the custom property `variant_id`. The first picture of a gallery is its primary one. Each gallery holds at most MAX_IMAGES pictures.
 * Uploaded and remote (imported) pictures share one order, so any picture can be moved anywhere.
 */
class ManageProductImages
{
    public const MAX_IMAGES = 12;
    public const COLLECTION = 'images';

    /** The option a picture belongs to (null = the product's own gallery). */
    public static function variantOf(Media $media): ?int
    {
        $id = $media->getCustomProperty('variant_id');

        return $id === null ? null : (int) $id;
    }

    /** @param  list<UploadedFile>  $files */
    public function add(Product $product, array $files, Admin $admin, ?int $variantId = null): void
    {
        $before = $this->gallery($product, $variantId);

        if (count($before) + count($files) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'A gallery can have at most '.self::MAX_IMAGES.' pictures ('.count($before).' already added).',
            ]);
        }

        foreach ($files as $file) {
            $adder = $product->addMedia($file);
            if ($variantId !== null) {
                $adder->withCustomProperties(['variant_id' => $variantId]);
            }
            $adder->toMediaCollection(self::COLLECTION);
        }

        // the new pictures go to the end, whatever kind the existing ones are
        $after = $this->gallery($product, $variantId);
        $this->applyOrder([...$before, ...array_values(array_diff($after, $before))]);
        Audit::log($admin, 'product.images_added', $product, ['count' => count($files), 'variant_id' => $variantId]);
    }

    /** `$id` is a media id, or "r<id>" for a remote (imported) picture. */
    public function delete(Product $product, string $id, Admin $admin): void
    {
        if (preg_match('/^r(\d+)$/', $id, $m)) {
            $deleted = ProductImage::query()->where('product_id', $product->id)->whereKey($m[1])->delete();
        } else {
            $media = $product->media()->where('collection_name', self::COLLECTION)->whereKey($id)->first();
            $media?->delete();
            $deleted = (bool) $media;
        }

        abort_unless($deleted, 404, 'Picture not found.');
        Audit::log($admin, 'product.image_deleted', $product, ['image' => $id]);
    }

    /**
     * @param  list<int|string>  $ids  picture ids (media id, or "r<id>") in the wanted order: EVERY picture of ONE gallery
     *                                 (the product's, or one option's)
     */
    public function reorder(Product $product, array $ids, Admin $admin): void
    {
        $ids = array_map('strval', $ids);
        $all = collect(ProductImages::all($this->fresh($product)))->keyBy(fn ($i) => (string) $i['id']);
        $first = $all->get($ids[0] ?? '');
        $gallery = $first ? $all->filter(fn ($i) => $i['variant_id'] === $first['variant_id'])->keys()->map(fn ($k) => (string) $k)->all() : [];

        if (! $first || count($ids) !== count($gallery) || array_diff($ids, $gallery) || array_diff($gallery, $ids)) {
            throw ValidationException::withMessages(['ids' => 'Send the id of every picture of one gallery, in the order you want.']);
        }

        $this->applyOrder($ids);
        Audit::log($admin, 'product.images_reordered', $product);
    }

    /** @return list<string> ids of one gallery in display order */
    private function gallery(Product $product, ?int $variantId): array
    {
        return collect(ProductImages::all($this->fresh($product)))
            ->filter(fn ($i) => $i['variant_id'] === $variantId)->map(fn ($i) => (string) $i['id'])->values()->all();
    }

    private function fresh(Product $product): Product
    {
        return Product::query()->with(['media', 'remoteImages'])->findOrFail($product->id);
    }

    /** Writes the positions 1..n (uploaded pictures: order_column, remote ones: sort_order). */
    private function applyOrder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            if (str_starts_with($id, 'r')) {
                ProductImage::query()->whereKey(substr($id, 1))->update(['sort_order' => $i + 1]);
            } else {
                Media::query()->whereKey($id)->update(['order_column' => $i + 1]);
            }
        }
    }
}

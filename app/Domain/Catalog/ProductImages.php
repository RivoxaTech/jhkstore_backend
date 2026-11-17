<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Actions\ManageProductImages;
use App\Domain\Catalog\Models\Product;
use App\Support\MediaDimensions;
use App\Support\MediaUrl;

/**
 * The pictures of a product as one list, whether they were uploaded (spatie media) or are remote urls (imported data).
 * Each picture carries `variant_id`: null = the product's own gallery, otherwise the option it belongs to.
 * Uploaded pictures come first, in their saved order; the first picture of a gallery is its primary one.
 * `media` and `remoteImages` must be eager loaded. URLs are absolute.
 */
class ProductImages
{
    /**
     * `width` / `height` belong to `url` (the original), `medium_width` / `medium_height` to `medium_url`; null when the size is unknown.
     *
     * @return list<array{id:int|string, source:string, variant_id:?int, url:string, medium_url:string, thumb_url:string, is_primary:bool, width:?int, height:?int, medium_width:?int, medium_height:?int}>
     */
    public static function all(Product $product): array
    {
        $images = [];

        foreach ($product->getMedia(ManageProductImages::COLLECTION) as $media) {
            $original = MediaDimensions::of($media);
            $medium = MediaDimensions::of($media, 'medium');
            $images[] = [
                'pos' => $media->order_column,
                'rank' => 0,
                'id' => $media->id,
                'source' => 'media',
                'variant_id' => ManageProductImages::variantOf($media),
                'url' => MediaUrl::media($media),
                'medium_url' => MediaUrl::media($media, 'medium'),
                'thumb_url' => MediaUrl::media($media, 'thumb'),
                'width' => $original['width'] ?? null,
                'height' => $original['height'] ?? null,
                'medium_width' => $medium['width'] ?? null,
                'medium_height' => $medium['height'] ?? null,
            ];
        }
        foreach ($product->remoteImages as $remote) {
            if ($remote->url && $remote->type === 'image') {
                $images[] = [
                    'pos' => $remote->sort_order, 'rank' => 1, 'id' => 'r'.$remote->id, 'source' => 'remote', 'variant_id' => $remote->variant_id,
                    'url' => $remote->url, 'medium_url' => $remote->url, 'thumb_url' => $remote->url,
                    'width' => null, 'height' => null, 'medium_width' => null, 'medium_height' => null,
                ];
            }
        }

        // one shared order: position, then uploaded before remote, then id
        usort($images, fn ($a, $b) => [$a['pos'], $a['rank']] <=> [$b['pos'], $b['rank']]);

        $seen = [];
        foreach ($images as $i => $image) {
            unset($images[$i]['pos'], $images[$i]['rank']);
            $key = $image['variant_id'] ?? 0;
            $images[$i]['is_primary'] = ! isset($seen[$key]);
            $seen[$key] = true;
        }

        return $images;
    }

    /** The pictures of one gallery (`null` = the product's own). If the product has none of its own, the pictures of its options are used. */
    public static function gallery(Product $product, ?int $variantId = null): array
    {
        $all = self::all($product);
        $own = array_values(array_filter($all, fn ($i) => $i['variant_id'] === $variantId));

        return ($own || $variantId !== null) ? $own : $all;
    }

    /** @return array{image_url: ?string, thumb_url: ?string, image_width: ?int, image_height: ?int}  the primary picture (its medium size), for cards and list rows */
    public static function primary(Product $product): array
    {
        $first = self::gallery($product)[0] ?? null;

        return [
            'image_url' => $first['medium_url'] ?? null, 'thumb_url' => $first['thumb_url'] ?? null,
            'image_width' => $first['medium_width'] ?? null, 'image_height' => $first['medium_height'] ?? null,
        ];
    }
}

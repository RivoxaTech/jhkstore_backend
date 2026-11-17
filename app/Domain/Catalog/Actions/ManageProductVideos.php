<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductVideos;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload, delete and reorder the videos of a product (at most `shop.video.max_files`), each with an optional poster picture. The files are
 * stored as they are: there is no transcoding. The request class has validated type and size.
 */
class ManageProductVideos
{
    public function add(Product $product, UploadedFile $video, ?UploadedFile $poster, Admin $admin): void
    {
        $max = (int) config('shop.video.max_files');
        if (ProductVideos::uploads($product)->count() >= $max) {
            throw ValidationException::withMessages(['video' => "A product can have at most $max uploaded videos. Delete one first."]);
        }

        $media = $product->addMedia($video)->toMediaCollection(ProductVideos::VIDEO);
        if ($poster) {
            $this->attachPoster($product, $media, $poster);
        }
        Audit::log($admin, 'product.video_added', $product, ['name' => $media->file_name]);
    }

    public function setPoster(Product $product, int $videoId, UploadedFile $poster, Admin $admin): void
    {
        $media = $this->video($product, $videoId);
        $this->attachPoster($product, $media, $poster);
        Audit::log($admin, 'product.video_poster_changed', $product, ['video' => $videoId]);
    }

    public function delete(Product $product, int $videoId, Admin $admin): void
    {
        $media = $this->video($product, $videoId);
        $this->posterOf($product, $videoId)?->delete();
        $media->delete();
        Audit::log($admin, 'product.video_deleted', $product, ['video' => $videoId]);
    }

    /** @param  list<int>  $ids  every uploaded video of the product, in the wanted order */
    public function reorder(Product $product, array $ids, Admin $admin): void
    {
        $have = ProductVideos::uploads($product)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) !== count($have) || array_diff($have, $ids) !== []) {
            throw ValidationException::withMessages(['ids' => 'Send every video of the product once.']);
        }
        Media::setNewOrder($ids);
        Audit::log($admin, 'product.videos_reordered', $product, ['ids' => $ids]);
    }

    private function video(Product $product, int $id): Media
    {
        $media = $product->media()->where('collection_name', ProductVideos::VIDEO)->whereKey($id)->first();
        abort_unless($media, 404, 'Video not found.');

        return $media;
    }

    private function posterOf(Product $product, int $videoId): ?Media
    {
        return $product->media()->where('collection_name', ProductVideos::POSTER)->get()->first(fn (Media $m) => (int) $m->getCustomProperty('video_id') === $videoId);
    }

    private function attachPoster(Product $product, Media $video, UploadedFile $poster): void
    {
        $this->posterOf($product, $video->id)?->delete();   // one poster per video
        $product->addMedia($poster)->withCustomProperties(['video_id' => $video->id])->toMediaCollection(ProductVideos::POSTER);
    }
}

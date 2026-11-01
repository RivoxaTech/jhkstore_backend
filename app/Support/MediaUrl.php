<?php

namespace App\Support;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Image/file URLs for API responses.
 * Always absolute (http://host/storage/...), because the frontend runs on a different origin and a relative
 * "/storage/..." path would be requested from the frontend's own server and show a broken image.
 * Returns null when there is no file, so the frontend can show its own placeholder.
 */
class MediaUrl
{
    public static function of(HasMedia $model, string $collection, string $conversion = ''): ?string
    {
        $url = $model->getFirstMediaUrl($collection, $conversion);
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    /** URL of one media item (or one of its conversions, when that conversion exists); absolute. */
    public static function media(Media $media, string $conversion = ''): string
    {
        $url = ($conversion !== '' && $media->hasGeneratedConversion($conversion)) ? $media->getUrl($conversion) : $media->getUrl();

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}

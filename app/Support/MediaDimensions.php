<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Width and height of an uploaded picture or one of its conversions, so the storefront can reserve the space (no layout shift).
 * Read from the file once and kept in the media row (custom property `dimensions`), so a list does not open files again.
 * Pictures on a disk that is not local (or remote URLs) have no dimensions: null.
 */
class MediaDimensions
{
    /** @return array{width: int, height: int}|null */
    public static function of(Media $media, string $conversion = ''): ?array
    {
        $key = $conversion === '' ? 'original' : $conversion;
        $known = $media->getCustomProperty('dimensions', []);
        if (isset($known[$key])) {
            return $known[$key];
        }

        try {
            $path = $conversion === '' ? $media->getPath() : ($media->hasGeneratedConversion($conversion) ? $media->getPath($conversion) : null);
            $size = $path && is_file($path) ? @getimagesize($path) : false;
        } catch (\Throwable) {
            $size = false;
        }
        if (! $size) {
            return null;
        }

        $known[$key] = ['width' => (int) $size[0], 'height' => (int) $size[1]];
        $media->setCustomProperty('dimensions', $known)->saveQuietly();

        return $known[$key];
    }
}

<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Support\MediaUrl;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The videos of a product as the API shows them: the uploaded ones (medialibrary collection `video`, each with an optional poster in
 * `video_poster`, linked by the custom property `video_id`) in their order, then the optional `video_url` (YouTube, Vimeo or a direct file).
 * Needs the product's `media` relation loaded (the product page and the cards already load it).
 */
class ProductVideos
{
    public const VIDEO = 'video';

    public const POSTER = 'video_poster';

    /** Hosts whose links are turned into an embeddable player address. */
    private const YOUTUBE = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com'];

    private const VIMEO = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    /**
     * What one uploaded video may weigh, in KB: the shop's own limit, or PHP's (the smaller of `upload_max_filesize` and `post_max_size`) when the
     * server allows less. The panel shows this one, so it never promises 50 MB on a host that stops at 20.
     */
    public static function effectiveMaxKb(): int
    {
        $kb = (int) config('shop.video.max_kb');
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes($key);
            if ($bytes > 0) {
                $kb = min($kb, intdiv($bytes, 1024));
            }
        }

        return $kb;
    }

    /** A php.ini size ("20M", "1G", "512K") in bytes; 0 when unset or unlimited. */
    public static function iniBytes(string $key): int
    {
        $value = ini_get($key);

        return is_string($value) && $value !== '' ? max(0, (int) ini_parse_quantity($value)) : 0;
    }

    /** @return list<array{url:string, poster_url:?string, mime:?string, source:string}> */
    public static function forStore(Product $product): array
    {
        $posters = self::postersByVideo($product);
        $out = [];
        foreach (self::uploads($product) as $media) {
            $poster = $posters[$media->id] ?? null;
            $out[] = ['url' => MediaUrl::media($media), 'poster_url' => $poster ? MediaUrl::media($poster) : null, 'mime' => $media->mime_type, 'source' => 'upload', 'uploaded_at' => $media->created_at?->toIso8601String()];   // the date search engines ask for with a video
        }
        if ($embed = self::embed((string) $product->video_url)) {
            $out[] = ['url' => $embed['url'], 'poster_url' => null, 'mime' => $embed['mime'], 'source' => 'embed'];
        }

        return $out;
    }

    /** The admin's view: the uploads with their ids (to delete, reorder or replace the poster) and the link. */
    public static function forAdmin(Product $product): array
    {
        $posters = self::postersByVideo($product);

        return self::uploads($product)->map(fn (Media $m) => [
            'id' => $m->id, 'name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size,
            'url' => MediaUrl::media($m), 'poster_url' => isset($posters[$m->id]) ? MediaUrl::media($posters[$m->id]) : null,
        ])->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, Media> the uploaded videos in order */
    public static function uploads(Product $product)
    {
        return $product->media->where('collection_name', self::VIDEO)->sortBy('order_column')->values();
    }

    /** @return array<int, Media> video media id => poster */
    private static function postersByVideo(Product $product): array
    {
        $out = [];
        foreach ($product->media->where('collection_name', self::POSTER) as $poster) {
            $out[(int) $poster->getCustomProperty('video_id')] = $poster;
        }

        return $out;
    }

    /**
     * An https video link → [url to play or embed, mime]. YouTube and Vimeo links become player addresses (mime null), a direct .mp4 / .webm
     * file stays as it is. Null when the link is not one of these.
     *
     * @return array{url:string, mime:?string}|null
     */
    public static function embed(string $link): ?array
    {
        $link = trim($link);
        $parts = parse_url($link);
        if ($link === '' || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, self::YOUTUBE, true)) {
            $id = $host === 'youtu.be' ? ltrim($path, '/') : ($query['v'] ?? (preg_match('#^/(?:embed|shorts|live)/([\w-]{6,20})#', $path, $m) ? $m[1] : ''));

            return preg_match('/^[\w-]{6,20}$/', (string) $id) ? ['url' => 'https://www.youtube-nocookie.com/embed/'.$id, 'mime' => null] : null;
        }
        if (in_array($host, self::VIMEO, true)) {
            return preg_match('#/(\d{5,12})(?:/|$)#', $path, $m) ? ['url' => 'https://player.vimeo.com/video/'.$m[1], 'mime' => null] : null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm'], true)) {
            return ['url' => $link, 'mime' => $ext === 'mp4' ? 'video/mp4' : 'video/webm'];
        }

        return null;
    }
}

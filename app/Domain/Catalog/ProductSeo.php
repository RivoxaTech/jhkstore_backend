<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Support\Settings;
use Illuminate\Support\Str;

/**
 * The page title and description of a product for search engines and shared links: the owner's own SEO fields when they filled them,
 * otherwise made from what the product already says (name, category, short description, description, store tagline). Nothing has to be typed.
 */
class ProductSeo
{
    public const DESCRIPTION_LIMIT = 155;

    /** @param  bool  $own  false = ignore the owner's own field (what would be made automatically) */
    public static function title(Product $product, bool $own = true): string
    {
        if ($own && filled($product->meta_title)) {
            return (string) $product->meta_title;
        }
        $category = $product->relationLoaded('category') ? $product->category?->name : null;

        return $category && ! Str::contains(Str::lower($product->name), Str::lower($category)) ? $product->name.' - '.$category : (string) $product->name;
    }

    public static function description(Product $product, bool $own = true): string
    {
        if ($own && filled($product->meta_description)) {
            return (string) $product->meta_description;
        }
        $settings = app(Settings::class);
        $category = $product->relationLoaded('category') ? $product->category?->name : null;
        $clean = fn (?string $text) => trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $text))));

        $sentence = fn (string $t) => preg_match('/[.!?]$/', $t) ? $t : $t.'.';
        $text = '';
        foreach (array_filter([$clean($product->short_description), $clean($product->description)]) as $part) {
            $candidate = $text === '' ? $sentence($part) : $text.' '.$sentence($part);
            if (mb_strlen($candidate) > self::DESCRIPTION_LIMIT && $text !== '') {
                break;
            }
            $text = $candidate;
        }
        if ($text === '') {
            $default = trim((string) $settings->get('seo_meta_description'));   // nothing to say about the product: the store's own default
            $text = $default !== '' ? $default : $product->name.($category ? ' - '.$category : '');
        }

        return Str::limit($text, self::DESCRIPTION_LIMIT, '...');
    }
}

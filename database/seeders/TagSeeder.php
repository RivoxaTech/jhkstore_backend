<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * The system tags: they drive the homepage sections (`/products?tag=best-seller`) and cannot be deleted.
 * Idempotent: matched by slug, so admin edits to colour / order / active are kept.
 */
class TagSeeder extends Seeder
{
    /** [name, slug, colour] */
    private const TAGS = [
        ['Best Seller', 'best-seller', '#f59e0b'],
        ['Hot Selling', 'hot-selling', '#ef4444'],
        ['New Arrival', 'new-arrival', '#10b981'],
        ['Best Price Zone', 'best-price-zone', '#6366f1'],
        ['Featured', 'featured', '#1d70ca'],
        ['Most Searched', 'most-searched', '#0ea5e9'],
        ['Freebie', 'freebie', '#ec4899'],
    ];

    public function run(): void
    {
        foreach (self::TAGS as $i => [$name, $slug, $color]) {
            $tag = Tag::query()->firstOrNew(['slug' => $slug]);
            if (! $tag->exists) {
                $tag->fill(['name' => $name, 'color' => $color, 'is_active' => true, 'sort_order' => $i + 1]);
                $tag->slug = $slug;
            }
            $tag->is_system = true;
            $tag->save();
        }
    }
}

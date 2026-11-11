<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A promotional banner. A placement (a spot on the storefront) holds one banner or many: the API just returns the live ones in order,
 * the storefront decides between a single banner and a slider. Pictures: `desktop` (required) and an optional `mobile` one.
 */
class Banner extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Placement key => label. Add a line here to give a storefront another spot. */
    public const PLACEMENTS = [
        'home_hero' => 'Home page – hero (top of the page)',
        'home_secondary' => 'Home page – secondary (between sections)',
        'promo_strip' => 'Promo strip (thin bar)',
    ];

    protected $fillable = ['placement', 'title', 'subtitle', 'button_text', 'link_url', 'sort_order', 'is_active', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('desktop')->singleFile()->useDisk('public');
        $this->addMediaCollection('mobile')->singleFile()->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(480)->performOnCollections('desktop', 'mobile')->nonQueued()->nonOptimized();
    }

    /** Active and inside its date window (an empty start / end is open-ended). */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** Order shown to customers and in the admin list. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** live | scheduled | expired | inactive */
    public function status(): string
    {
        $now = now();

        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at && $this->starts_at->gt($now) => 'scheduled',
            $this->ends_at && $this->ends_at->lt($now) => 'expired',
            default => 'live',
        };
    }
}

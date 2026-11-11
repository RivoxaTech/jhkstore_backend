<?php

namespace App\Domain\Catalog\Models;

use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** The two optional images of a category / sub-category: a small `icon` (with a 256px thumb) and a larger `image`. */
trait HasCatalogImages
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('icon')->singleFile()->useDisk('public');
        $this->addMediaCollection('image')->singleFile()->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(256)->height(256)->performOnCollections('icon')->nonQueued()->nonOptimized();
    }
}

<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing brand: only what the storefront needs.
 */
class StoreBrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => MediaUrl::of($this->resource, 'logo'),
            'logo_thumb_url' => MediaUrl::of($this->resource, 'logo', 'thumb'),
            'banner_url' => MediaUrl::of($this->resource, 'banner'),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->pluck('collection')),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
        ];
    }
}

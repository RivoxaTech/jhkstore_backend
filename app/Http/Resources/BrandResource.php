<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a brand.
 * `logo_url` is the brand's own logo, or the company's logo when the brand has none (`logo_source` tells which).
 */
class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $logo = MediaUrl::brandLogo($this->resource);
        $thumb = MediaUrl::brandLogo($this->resource, 'thumb');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'origin_country' => $this->whenLoaded('originCountry', fn () => $this->originCountry ? [
                'id' => $this->originCountry->id,
                'name' => $this->originCountry->name,
            ] : null),
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status->value,
            ]),
            'logo_url' => $logo['url'],
            'logo_thumb_url' => $thumb['url'],
            'logo_source' => $logo['source'],          // 'brand' | 'company' | null
            'has_own_logo' => $logo['source'] === 'brand',
            'banner_url' => MediaUrl::of($this->resource, 'banner'),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'created_at' => $this->created_at,
        ];
    }
}

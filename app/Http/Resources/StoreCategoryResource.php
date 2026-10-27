<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Customer-facing category: only what the storefront needs (sub-categories are added by CategoryTree). */
class StoreCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_top' => $this->is_top,
            'icon_url' => MediaUrl::of($this->resource, 'icon'),
            'image_url' => MediaUrl::of($this->resource, 'image'),
        ];
    }
}

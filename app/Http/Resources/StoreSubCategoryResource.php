<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreSubCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon_url' => MediaUrl::of($this->resource, 'icon'),
            'image_url' => MediaUrl::of($this->resource, 'image'),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

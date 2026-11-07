<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a category. Load it with `withProductCounts()` and `media` (and `subCategories.media`) to avoid N+1 queries. */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_top' => $this->is_top,
            'icon_url' => MediaUrl::of($this->resource, 'icon'),
            'icon_thumb_url' => MediaUrl::of($this->resource, 'icon', 'thumb'),
            'image_url' => MediaUrl::of($this->resource, 'image'),
            'sub_categories_count' => $this->sub_categories_count,
            'products_count' => (int) $this->products_count,
            'sub_categories' => SubCategoryResource::collection($this->whenLoaded('subCategories')),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'created_at' => $this->created_at,
        ];
    }
}

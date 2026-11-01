<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a sub-category. Load it with `withProductCounts()` and `media`. */
class SubCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'icon_url' => MediaUrl::of($this->resource, 'icon'),
            'icon_thumb_url' => MediaUrl::of($this->resource, 'icon', 'thumb'),
            'image_url' => MediaUrl::of($this->resource, 'image'),
            'products_count' => (int) $this->products_count,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'created_at' => $this->created_at,
        ];
    }
}

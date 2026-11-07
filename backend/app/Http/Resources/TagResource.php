<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a tag (`products_count` is added when the controller selects it). */
class TagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'style' => $this->style,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'products_count' => $this->whenCounted('products'),
        ];
    }
}

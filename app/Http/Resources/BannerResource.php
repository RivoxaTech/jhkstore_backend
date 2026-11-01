<?php

namespace App\Http\Resources;

use App\Domain\Content\Models\Banner;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a banner. Eager load `media` (the controller does). */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'placement' => $this->placement,
            'placement_label' => Banner::PLACEMENTS[$this->placement] ?? $this->placement,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'button_text' => $this->button_text,
            'link_url' => $this->link_url,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status(),
            'desktop_image_url' => MediaUrl::of($this->resource, 'desktop'),
            'desktop_thumb_url' => MediaUrl::of($this->resource, 'desktop', 'thumb'),
            'mobile_image_url' => MediaUrl::of($this->resource, 'mobile'),
            'mobile_thumb_url' => MediaUrl::of($this->resource, 'mobile', 'thumb'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

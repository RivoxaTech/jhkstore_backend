<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Customer-facing banner: only what the storefront needs. Images are absolute URLs; `mobile_image_url` is null when there is no mobile picture (use the desktop one). */
class StoreBannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'placement' => $this->placement,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'button_text' => $this->button_text,
            'link_url' => $this->link_url,
            'image_url' => MediaUrl::of($this->resource, 'desktop'),
            'mobile_image_url' => MediaUrl::of($this->resource, 'mobile'),
        ];
    }
}

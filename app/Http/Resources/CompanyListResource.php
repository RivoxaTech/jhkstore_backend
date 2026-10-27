<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact company row for the admin list: enough to decide without opening the company. */
class CompanyListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($relation) => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null;
        $brands = $this->brands->sortBy('sort_order');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'is_in_house' => $this->is_in_house,
            'logo_url' => MediaUrl::of($this->resource, 'logo'),
            'logo_thumb_url' => MediaUrl::of($this->resource, 'logo', 'thumb'),
            'registration_type' => $ref('registrationType'),
            'vendor_type' => $ref('vendorType'),
            'origin_country' => $ref('originCountry'),
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'vat_gst_number' => $this->vat_gst_number,
            'contact' => ['name' => $this->contact_name, 'mobile' => $this->contact_mobile, 'email' => $this->contact_email],
            'city' => $this->city,
            'zip_code' => $this->zip_code,
            'brands_count' => $this->brands_count,
            'brand_names' => $brands->take(3)->pluck('name')->values(),
            'catalogue_count' => $this->media->where('collection_name', 'catalogue')->count(),
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
        ];
    }
}

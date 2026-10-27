<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a company (list and detail). */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($relation) => $this->whenLoaded($relation, fn () => $this->{$relation} ? [
            'id' => $this->{$relation}->id,
            'name' => $this->{$relation}->name,
        ] : null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'is_in_house' => $this->is_in_house,
            'registration_type' => $ref('registrationType'),
            'vendor_type' => $ref('vendorType'),
            'origin_country' => $ref('originCountry'),
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'vat_gst_number' => $this->vat_gst_number,
            'contact' => [
                'name' => $this->contact_name,
                'mobile' => $this->contact_mobile,
                'email' => $this->contact_email,
                'whatsapp' => $this->contact_whatsapp,
            ],
            'address' => [
                'address' => $this->address,
                'landmark' => $this->landmark,
                'city' => $this->city,
                'zip_code' => $this->zip_code,
            ],
            'promotion_support' => $this->promotion_support,
            'brands_count' => $this->whenCounted('brands'),
            'brands' => BrandResource::collection($this->whenLoaded('brands')),
            'catalogue' => $this->whenLoaded('media', fn () => $this->getMedia('catalogue')->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->file_name,
                'mime_type' => $m->mime_type,
                'size' => $m->size,
                // Catalogue files are private: this endpoint requires the admin token.
                'download_url' => route('admin.companies.catalogue', ['company' => $this->id, 'media' => $m->id]),
            ])->values()),
            'logo_url' => $this->whenLoaded('media', fn () => MediaUrl::of($this->resource, 'logo')),
            'logo_thumb_url' => $this->whenLoaded('media', fn () => MediaUrl::of($this->resource, 'logo', 'thumb')),
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
        ];
    }
}

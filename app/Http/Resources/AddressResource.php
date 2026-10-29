<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A saved address of the customer. line1 = clinic name / house no. / building / apartment; area = area / street / sector / village. */
class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'name' => $this->name,
            'phone' => $this->phone,
            'alt_phone' => $this->alt_phone,
            'line1' => $this->line1,
            'area' => $this->area,
            'landmark' => $this->landmark,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'gst_number' => $this->gst_number,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->is_default,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A wholesale request for the admin screen (personal data: only behind `wholesale.view`). */
class WholesaleRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'status' => $r->status,
            'name' => $r->name,
            'phone' => $r->phone,
            'email' => $r->email,
            'business_name' => $r->business_name,
            'product_id' => $r->product_id,
            'variant_id' => $r->variant_id,
            'product_name' => $r->product_name,
            'quantity' => $r->quantity,
            'message' => $r->message,
            'admin_note' => $r->admin_note,
            'handled_at' => $r->handled_at,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}

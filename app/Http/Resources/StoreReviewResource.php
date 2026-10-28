<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** A published review. The author is shown as first name + last initial (needs the `user` relation loaded). */
class StoreReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'author' => $this->author(),
            'is_verified_purchase' => $this->is_verified_purchase,
            'created_at' => $this->created_at,
        ];
    }

    private function author(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->user?->full_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $parts) {
            return 'Customer';
        }

        return count($parts) > 1 ? $parts[0].' '.Str::upper(Str::substr(end($parts), 0, 1)).'.' : $parts[0];
    }
}

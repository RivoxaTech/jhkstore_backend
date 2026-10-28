<?php

namespace App\Domain\Review\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReview extends Model
{
    use SoftDeletes;

    public const PUBLISHED = 'published';

    protected $fillable = ['product_id', 'user_id', 'rating', 'title', 'body', 'status'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_verified_purchase' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

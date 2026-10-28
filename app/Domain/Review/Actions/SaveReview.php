<?php

namespace App\Domain\Review\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\Product;
use App\Domain\Review\Models\ProductReview;
use Illuminate\Support\Facades\DB;

/** A customer's review of a product: one per user and product - posting again updates it. */
class SaveReview
{
    public function __construct(private RecomputeProductRating $rating) {}

    /** @return array{0: ProductReview, 1: bool}  the review and whether it was newly created */
    public function handle(Product $product, User $user, array $data): array
    {
        return DB::transaction(function () use ($product, $user, $data) {
            // Lock the product row so two simultaneous reviews recompute the numbers one after the other.
            Product::query()->whereKey($product->id)->lockForUpdate()->value('id');

            $review = ProductReview::withTrashed()->where('product_id', $product->id)->where('user_id', $user->id)->first();
            $created = $review === null || $review->trashed();

            $review ??= new ProductReview(['product_id' => $product->id, 'user_id' => $user->id]);
            if ($review->trashed()) {
                $review->restore();
            }
            $review->fill([
                'rating' => $data['rating'],
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? null,
                'status' => ProductReview::PUBLISHED,
            ])->forceFill(['is_verified_purchase' => $review->is_verified_purchase ?? false])->save();

            $this->rating->handle($product->id);

            return [$review, $created];
        });
    }
}

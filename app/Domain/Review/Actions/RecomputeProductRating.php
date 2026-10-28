<?php

namespace App\Domain\Review\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Review\Models\ProductRatingStat;
use App\Domain\Review\Models\ProductReview;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds product_rating_stats (average, count, 1..5 distribution) and products.rating_avg / rating_count from the
 * published reviews. Call inside the transaction that changed a review, so the numbers are never out of step.
 */
class RecomputeProductRating
{
    public function handle(int $productId): ProductRatingStat
    {
        $counts = ProductReview::query()
            ->where('product_id', $productId)->where('status', ProductReview::PUBLISHED)
            ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');

        $count = (int) $counts->sum();
        $avg = $count ? round($counts->map(fn ($n, $rating) => $n * $rating)->sum() / $count, 2) : 0;

        $stat = ProductRatingStat::query()->updateOrCreate(['product_id' => $productId], [
            'avg' => $avg,
            'count' => $count,
            'c1' => (int) ($counts[1] ?? 0), 'c2' => (int) ($counts[2] ?? 0), 'c3' => (int) ($counts[3] ?? 0),
            'c4' => (int) ($counts[4] ?? 0), 'c5' => (int) ($counts[5] ?? 0),
            'updated_at' => now(),
        ]);

        DB::table('products')->where('id', $productId)->update([
            'rating_avg' => round($avg, 1),
            'rating_count' => $count,
        ]);

        return $stat;
    }
}

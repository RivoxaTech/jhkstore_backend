<?php

namespace App\Http\Controllers\Store;

use App\Domain\Catalog\Models\Product;
use App\Domain\Review\Actions\SaveReview;
use App\Domain\Review\Models\ProductRatingStat;
use App\Domain\Review\Models\ProductReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\PostReviewRequest;
use App\Http\Resources\StoreReviewResource;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** Published reviews, newest first, with the summary {avg, count, distribution 1..5}. Query: ?page ?per_page (max 50) */
    public function index(Request $request, string $slug)
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $product = $this->visibleProduct($slug);

        $reviews = ProductReview::query()
            ->where('product_id', $product->id)->where('status', ProductReview::PUBLISHED)
            ->with('user:id,full_name')
            ->latest('id')
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        return StoreReviewResource::collection($reviews)->additional(['summary' => $this->summary($product->id)]);
    }

    /**
     * Post a review (customer token): rating 1-5, optional title and body. One review per customer and product:
     * posting again updates it (200); the first post answers 201. The product's rating numbers are recomputed in the same transaction.
     */
    public function store(PostReviewRequest $request, string $slug, SaveReview $save)
    {
        $product = $this->visibleProduct($slug);
        [$review, $created] = $save->handle($product, $request->user(), $request->validated());
        $review->setRelation('user', $request->user());

        return (new StoreReviewResource($review))
            ->additional(['summary' => $this->summary($product->id)])
            ->response()->setStatusCode($created ? 201 : 200);
    }

    private function visibleProduct(string $slug): Product
    {
        return Product::query()->storefront(null)->where('products.slug', $slug)->firstOrFail(['products.id']);
    }

    private function summary(int $productId): array
    {
        $s = ProductRatingStat::query()->find($productId);

        return [
            'avg' => (float) ($s->avg ?? 0),
            'count' => (int) ($s->count ?? 0),
            'distribution' => [1 => (int) ($s->c1 ?? 0), 2 => (int) ($s->c2 ?? 0), 3 => (int) ($s->c3 ?? 0), 4 => (int) ($s->c4 ?? 0), 5 => (int) ($s->c5 ?? 0)],
        ];
    }
}

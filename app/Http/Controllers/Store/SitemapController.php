<?php

namespace App\Http\Controllers\Store;

use App\Domain\Catalog\Sitemap;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SitemapController extends Controller
{
    /**
     * Every visible product, category and sub-category (slug, date, one picture), for the static build and for sitemap.xml.
     * `{products: [{slug, category_slug, updated_at, image_url}], categories: [{slug, updated_at}], sub_categories: [{slug, category_slug, updated_at}]}`.
     * No prices. Cached 5 minutes on the server and by the CDN.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(Sitemap::build());
    }
}

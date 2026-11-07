<?php

namespace App\Http\Middleware;

use App\Domain\Catalog\Sitemap;
use App\Domain\Support\Settings;
use App\Domain\Website\Rebuild;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watches the admin API for writes that change what the static storefront shows, so the website can be rebuilt (Rebuild) and the cached sitemap
 * is dropped. It looks at the ROUTE, not at the data: only the admin screens below count. Orders, customers, carts, wholesale requests, coupons, delivery
 * zones and the stock a sale takes never trigger anything (a sale is not an admin write: it never passes here).
 *
 * Counted: products (options, stock, prices, status, pictures, videos, tags of a product), categories and sub-categories, tags, banners, discounts
 * (they change shown prices) and the settings that are public (every group except notifications and website itself), including the logo,
 * favicon and share picture.
 *
 * It runs after the response and never fails it: a problem here is logged and ignored.
 */
class TrackStorefrontChanges
{
    /** First URL segment (after /api/v1/admin/) => writes under it change the storefront. */
    private const SECTIONS = ['products', 'categories', 'sub-categories', 'tags', 'banners', 'discounts'];

    /** Settings groups that never reach the storefront. */
    private const PRIVATE_GROUPS = ['notifications', 'website'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! $request->isMethodSafe() && $response->getStatusCode() < 400 && $this->visible($request)) {
                Sitemap::forget();
                app(Rebuild::class)->changed();
            }
        } catch (\Throwable $e) {
            Log::warning('Storefront change tracking failed: '.$e->getMessage());
        }

        return $response;
    }

    private function visible(Request $request): bool
    {
        $segment = explode('/', trim(substr((string) $request->route()?->uri(), strlen('api/v1/admin/')), '/'))[0] ?? '';
        if (in_array($segment, self::SECTIONS, true)) {
            return true;
        }
        if ($segment !== 'settings') {
            return false;
        }
        if ($request->route('slot')) {
            return true;   // logo, favicon, share picture
        }
        foreach (array_keys($request->all()) as $key) {
            $group = Settings::DEFINITIONS[$key][0] ?? null;
            if ($group !== null && ! in_array($group, self::PRIVATE_GROUPS, true)) {
                return true;
            }
        }

        return false;
    }
}

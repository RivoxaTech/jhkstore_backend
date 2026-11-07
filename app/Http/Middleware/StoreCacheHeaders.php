<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache headers of the storefront API (for Cloudflare and browsers).
 *
 * Only the public catalogue reads are cacheable: settings, banners, categories, products (list and detail), tags and the sitemap, and only a successful
 * GET without an `X-Cart-Token`. They get `Cache-Control: public, max-age, s-maxage, stale-while-revalidate` (config shop.cache) and an ETag, and a
 * matching `If-None-Match` is answered with 304. EVERYTHING else (cart, quote, coupons, orders, order lookup, payment proof, wholesale, errors,
 * anything that carries a cart token) is `no-store`. The decision is by route NAME (the list below), so a new store route is private until it is added here.
 */
class StoreCacheHeaders
{
    /** Route names (see routes/api_store.php) whose answer is the same for everybody. */
    public const PUBLIC_ROUTES = [
        'store.settings', 'store.banners', 'store.categories.index', 'store.categories.show',
        'store.products.index', 'store.products.show', 'store.tags', 'store.sitemap',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $cacheable = $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && ! $request->headers->has('X-Cart-Token')
            && in_array($request->route()?->getName(), self::PUBLIC_ROUTES, true);

        if (! $cacheable) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $response->headers->set('Cache-Control', sprintf(
            'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
            config('shop.cache.max_age'), config('shop.cache.s_maxage'), config('shop.cache.stale_while_revalidate'),
        ));
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);   // turns the response into an empty 304 when the visitor already has this version

        return $response;
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Sitemap;
use App\Http\Middleware\StoreCacheHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** What the static storefront needs from the API: the sitemap, cache headers, CORS and the SEO fields of products. */
class StorefrontPrepTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function queries(callable $call): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ------------------------------------------------------------------ sitemap

    public function test_the_sitemap_lists_only_what_customers_can_see_and_no_prices(): void
    {
        $toys = $this->makeCategory('Toys');
        $sub = $this->makeSub($toys, 'Blocks');
        $hiddenCategory = $this->makeCategory('Hidden category', active: false);
        $hiddenSub = $this->makeSub($toys, 'Hidden sub', active: false);

        $visible = $this->makeProduct($toys, ['name' => 'Visible block', 'sub_category_id' => $sub->id]);
        $this->makeProduct($toys, ['name' => 'Draft block', 'status' => 'draft']);
        $this->makeProduct($hiddenCategory, ['name' => 'In hidden category']);
        $this->makeProduct($toys, ['name' => 'In hidden sub', 'sub_category_id' => $hiddenSub->id]);
        $noOption = $this->makeProduct($toys, ['name' => 'No active option']);
        $noOption->variants()->update(['is_active' => false]);

        $data = $this->getJson(self::STORE.'/sitemap')->assertOk()->json();

        $this->assertSame([$visible->slug], array_column($data['products'], 'slug'));
        $this->assertSame($toys->slug, $data['products'][0]['category_slug']);
        $this->assertEqualsCanonicalizing(['slug', 'category_slug', 'updated_at', 'image_url'], array_keys($data['products'][0]));
        $this->assertNotFalse(strtotime($data['products'][0]['updated_at']));

        $categories = array_column($data['categories'], 'slug');
        $this->assertContains($toys->slug, $categories);
        $this->assertNotContains($hiddenCategory->slug, $categories);
        $this->assertSame([['slug' => $sub->slug, 'category_slug' => $toys->slug]], array_map(fn ($s) => ['slug' => $s['slug'], 'category_slug' => $s['category_slug']], $data['sub_categories']));
        $this->assertEqualsCanonicalizing(['slug', 'updated_at'], array_keys($data['categories'][0]));

        $body = json_encode($data);
        foreach (['price', 'mrp', 'stock', 'sku', 'cost'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_sitemap_has_a_picture_url_and_a_fixed_number_of_queries(): void
    {
        $category = $this->makeCategory('Toys');
        $first = $this->makeProduct($category);
        $this->actingAsAdmin('developer');
        $this->post(self::ADMIN."/products/{$first->id}/images", ['images' => [UploadedFile::fake()->image('a.jpg', 800, 800)]], ['Accept' => 'application/json'])->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $few = $this->queries(function () {
            Cache::flush();
            $this->getJson(self::STORE.'/sitemap')->assertOk();
        });
        $this->assertStringContainsString('/storage/', $this->getJson(self::STORE.'/sitemap')->json('products.0.image_url'));

        for ($i = 0; $i < 30; $i++) {
            $this->makeProduct($category);
        }
        $many = $this->queries(function () {
            Cache::flush();
            $this->assertCount(31, $this->getJson(self::STORE.'/sitemap')->assertOk()->json('products'));
        });

        $this->assertSame($few, $many, 'the sitemap must not run more queries for more products');
        $this->assertLessThan(15, $many);
    }

    public function test_the_sitemap_is_cached_for_five_minutes_and_dropped_on_an_admin_write(): void
    {
        $category = $this->makeCategory('Toys');
        $this->makeProduct($category, ['name' => 'First']);
        $this->assertCount(1, $this->getJson(self::STORE.'/sitemap')->json('products'));

        $this->makeProduct($category, ['name' => 'Second']);              // written behind the admin API: the cache still holds the old list
        $this->assertCount(1, $this->getJson(self::STORE.'/sitemap')->json('products'));
        $this->assertSame(300, Sitemap::TTL);

        $this->actingAsAdmin('developer');
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Seasonal', 'slug' => 'seasonal'])->assertCreated();   // an admin write drops it
        $this->app['auth']->forgetGuards();
        $this->assertCount(2, $this->getJson(self::STORE.'/sitemap')->json('products'));
    }

    public function test_the_sitemap_is_public_and_throttled(): void
    {
        $this->getJson(self::STORE.'/sitemap')->assertOk()->assertHeader('X-RateLimit-Limit');
    }

    // ------------------------------------------------------------------ cache headers

    public function test_public_catalogue_reads_are_cacheable_with_an_etag_and_304(): void
    {
        $category = $this->makeCategory('Toys');
        $product = $this->makeProduct($category);

        $public = ['settings', 'banners', 'categories', 'categories/'.$category->slug, 'products', 'products/'.$product->slug, 'tags', 'sitemap'];
        foreach ($public as $path) {
            $response = $this->getJson(self::STORE.'/'.$path)->assertOk();
            $cache = (string) $response->headers->get('Cache-Control');
            foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=60'] as $part) {
                $this->assertStringContainsString($part, $cache, "$path: $cache");
            }
            $etag = $response->headers->get('ETag');
            $this->assertNotEmpty($etag, "$path has no ETag");

            // the same answer again, with the ETag: 304 and no body
            $again = $this->getJson(self::STORE.'/'.$path, ['If-None-Match' => $etag]);
            $again->assertStatus(304);
            $this->assertSame('', $again->getContent());
            $this->assertStringContainsString('s-maxage=300', (string) $again->headers->get('Cache-Control'));

            // a different tag = a full answer
            $this->getJson(self::STORE.'/'.$path, ['If-None-Match' => '"nope"'])->assertOk();
        }
    }

    public function test_the_cache_values_come_from_the_config(): void
    {
        config(['shop.cache' => ['max_age' => 5, 's_maxage' => 30, 'stale_while_revalidate' => 7]]);
        $header = (string) $this->getJson(self::STORE.'/tags')->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=5', $header);
        $this->assertStringContainsString('s-maxage=30', $header);
        $this->assertStringContainsString('stale-while-revalidate=7', $header);
    }

    public function test_the_cart_token_or_an_error_is_never_cached(): void
    {
        $category = $this->makeCategory('Toys');
        $product = $this->makeProduct($category);

        // a catalogue read that carries a cart token is private
        $this->getJson(self::STORE.'/products', ['X-Cart-Token' => str_repeat('a', 40)])->assertOk();
        $this->assertStringContainsString('no-store', (string) $this->getJson(self::STORE.'/products', ['X-Cart-Token' => str_repeat('a', 40)])->headers->get('Cache-Control'));
        // a 404 is never cached (a product created after the build must show at once)
        $this->assertStringContainsString('no-store', (string) $this->getJson(self::STORE.'/products/no-such-product')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->getJson(self::STORE.'/categories/no-such-category')->headers->get('Cache-Control'));
        $this->getJson(self::STORE.'/products/no-such-product')->assertNotFound();
        $this->getJson(self::STORE.'/categories/no-such-category')->assertNotFound();
        // a rejected request is not cached either
        $this->getJson(self::STORE.'/products?per_page=1000')->assertStatus(422);
        $this->assertStringContainsString('no-store', (string) $this->getJson(self::STORE.'/products?per_page=1000')->headers->get('Cache-Control'));
        $this->assertNotNull($product);
    }

    /** Every route of the store API: the public catalogue reads are cacheable, every other one is no-store. A new route is private until it is listed. */
    public function test_every_store_route_sends_the_right_cache_header(): void
    {
        $category = $this->makeCategory('Toys');
        $product = $this->makeProduct($category);
        $number = $this->placeOrder($this->orderBody([$this->variantOf($this->shopProduct()) => 1]))->assertCreated()->json('data.order_number');
        $fill = ['slug' => $product->slug, 'orderNumber' => $number, 'variantId' => 1];
        $categorySlug = $category->slug;

        $seen = 0;
        $publicSeen = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/store')) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => ($route->getName() === 'store.categories.show' ? $categorySlug : ($fill[$m[1]] ?? 'x')), $route->uri());
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $response = $this->call($method, '/'.$uri, $method === 'GET' ? [] : [], [], [], ['HTTP_ACCEPT' => 'application/json']);
                $cache = (string) $response->headers->get('Cache-Control');
                $isPublic = $method === 'GET' && in_array($route->getName(), StoreCacheHeaders::PUBLIC_ROUTES, true);
                if ($isPublic) {
                    $this->assertSame(200, $response->getStatusCode(), "$method $uri");
                    $this->assertStringContainsString('public', $cache, "$method $uri");
                    $this->assertStringContainsString('s-maxage=300', $cache, "$method $uri");
                    $this->assertNotEmpty($response->headers->get('ETag'), "$method $uri");
                    $publicSeen[] = $route->getName();
                } else {
                    $this->assertStringContainsString('no-store', $cache, "$method $uri must be no-store, got [$cache]");
                    $this->assertStringNotContainsString('public', $cache, "$method $uri");
                }
                $seen++;
            }
        }

        $this->assertGreaterThanOrEqual(20, $seen);
        $this->assertEqualsCanonicalizing(StoreCacheHeaders::PUBLIC_ROUTES, array_values(array_unique($publicSeen)), 'a public route name in the list does not exist, or one is missing a name');
    }

    public function test_admin_routes_are_never_cached(): void
    {
        $this->actingAsAdmin('developer');
        foreach (['settings', 'website/status', 'products', 'categories', 'access-control', 'auth/me'] as $path) {
            $cache = (string) $this->getJson(self::ADMIN.'/'.$path)->assertOk()->headers->get('Cache-Control');
            $this->assertStringNotContainsString('public', $cache, $path);
            $this->assertStringNotContainsString('s-maxage', $cache, $path);
        }
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_allows_the_local_storefront_and_exposes_the_cart_token(): void
    {
        $shipped = require config_path('cors.php');
        $this->assertContains('X-Cart-Token', $shipped['exposed_headers']);
        $this->assertContains('http://localhost:3000', explode(',', $this->envExampleUrls()));

        config(['cors.allowed_origins' => ['http://localhost:3000', 'https://shop.example.com']]);
        $response = $this->getJson(self::STORE.'/settings', ['Origin' => 'http://localhost:3000']);
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('X-Cart-Token', (string) $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));   // one cached copy per origin
    }

    public function test_a_pages_dev_pattern_can_be_allowed_and_nothing_else_is(): void
    {
        config(['cors.allowed_origins' => [], 'cors.allowed_origins_patterns' => ['#^https://[a-z0-9-]+\.my-store\.pages\.dev$#']]);
        $this->assertSame('https://abc123.my-store.pages.dev', $this->getJson(self::STORE.'/settings', ['Origin' => 'https://abc123.my-store.pages.dev'])->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($this->getJson(self::STORE.'/settings', ['Origin' => 'https://evil.pages.dev'])->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($this->getJson(self::STORE.'/settings', ['Origin' => 'https://abc123.my-store.pages.dev.evil.com'])->headers->get('Access-Control-Allow-Origin'));
    }

    private function envExampleUrls(): string
    {
        preg_match('/^FRONTEND_URLS=(.*)$/m', file_get_contents(base_path('.env.example')), $m);

        return $m[1] ?? '';
    }

    // ------------------------------------------------------------------ SEO fields of the product list and detail

    public function test_the_product_card_and_detail_carry_what_a_static_page_needs(): void
    {
        $toys = $this->makeCategory('Toys');
        $sub = $this->makeSub($toys, 'Blocks');
        $product = $this->makeProduct($toys, ['name' => 'Rainbow blocks', 'sub_category_id' => $sub->id, 'short_description' => 'Soft wooden blocks.', 'mrp' => 1000, 'selling_price' => 800]);
        $this->actingAsAdmin('developer');
        $this->post(self::ADMIN."/products/{$product->id}/images", ['images' => [UploadedFile::fake()->image('a.jpg', 800, 600)]], ['Accept' => 'application/json'])->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $card = $this->getJson(self::STORE.'/products')->assertOk()->json('data.0');
        $this->assertSame('in_stock', $card['availability']);
        $this->assertNotFalse(strtotime($card['updated_at']));
        $this->assertSame(800.0, (float) $card['price']);
        $this->assertSame(1000.0, (float) $card['compare_price']);
        $this->assertNotNull($card['image_url']);
        $this->assertIsInt($card['image_width']);
        $this->assertIsInt($card['image_height']);
        $this->assertEqualsWithDelta(4 / 3, $card['image_width'] / $card['image_height'], 0.02);
        $this->assertSame($card['image_width'], $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.images.0.medium_width'));

        $detail = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->json('data');
        $this->assertSame('Rainbow blocks', $detail['seo']['title']);                       // no own meta title: the name
        $this->assertSame('Soft wooden blocks.', $detail['seo']['description']);            // no own meta description: the short description
        $this->assertSame($detail['image_url'], $detail['seo']['image_url']);
        $this->assertSame(['category', 'sub_category', 'product'], array_column($detail['breadcrumbs'], 'type'));
        $this->assertSame([$toys->slug, $sub->slug, $product->slug], array_column($detail['breadcrumbs'], 'slug'));
        $image = $detail['images'][0];
        $this->assertSame([800, 600], [$image['width'], $image['height']]);
        $this->assertEqualsWithDelta(4 / 3, $image['medium_width'] / $image['medium_height'], 0.02);   // the medium picture keeps the ratio
        $this->assertLessThan(800, $image['medium_width']);
        $this->assertSame('in_stock', $detail['availability']);
        $this->assertArrayHasKey('updated_at', $detail);
    }

    public function test_own_seo_fields_win_and_the_store_defaults_are_the_last_fallback(): void
    {
        $this->setSetting('seo_meta_description', 'The store default description');
        $toys = $this->makeCategory('Toys');
        $own = $this->makeProduct($toys, ['name' => 'Own SEO', 'meta_title' => 'My own title', 'meta_description' => 'My own description']);
        $plain = $this->makeProduct($toys, ['name' => 'Plain', 'short_description' => null]);

        $a = $this->getJson(self::STORE.'/products/'.$own->slug)->json('data');
        $this->assertSame('My own title', $a['seo']['title']);
        $this->assertSame('My own description', $a['seo']['description']);

        $b = $this->getJson(self::STORE.'/products/'.$plain->slug)->json('data');
        $this->assertSame('Plain', $b['seo']['title']);
        $this->assertSame('The store default description', $b['seo']['description']);
        $this->assertNull($b['image_url']);
    }

    public function test_a_card_carries_the_option_to_add_and_the_minimum_quantity(): void
    {
        $toys = $this->makeCategory('Toys');
        $product = $this->makeProduct($toys, ['name' => 'Pair pack', 'min_order_qty' => 2, 'max_order_qty' => 6]);

        $card = $this->getJson(self::STORE.'/products')->assertOk()->json('data.0');
        $this->assertSame($product->variants()->value('id'), $card['variant_id']);
        $this->assertSame(2, $card['min_order_qty']);
        // a card's option can be added to the cart as it is
        $this->postJson(self::STORE.'/cart/items', ['variant_id' => $card['variant_id'], 'qty' => $card['min_order_qty']])->assertSuccessful();
    }

    public function test_a_sold_out_product_says_so_in_its_availability(): void
    {
        $toys = $this->makeCategory('Toys');
        $product = $this->makeProduct($toys, ['name' => 'Gone']);
        Product::query()->whereKey($product->id)->update(['is_sold_out' => true]);

        $this->assertSame('out_of_stock', $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.availability'));
    }

    public function test_categories_carry_their_seo_fields_and_date(): void
    {
        $toys = $this->makeCategory('Toys');
        $this->makeSub($toys, 'Blocks');
        $category = $this->getJson(self::STORE.'/categories/'.$toys->slug)->assertOk()->json('data');

        $this->assertArrayHasKey('meta_title', $category);
        $this->assertArrayHasKey('meta_description', $category);
        $this->assertNotFalse(strtotime($category['updated_at']));
        $this->assertArrayHasKey('meta_title', $category['sub_categories'][0]);
        $this->assertNotFalse(strtotime($category['sub_categories'][0]['updated_at']));
    }
}

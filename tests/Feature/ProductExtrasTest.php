<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductVideos;
use App\Domain\Support\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Phase B product features: the sold-out and featured switches, the new list filters and the product video (upload, poster, link, public API). */
class ProductExtrasTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
    }

    private function product(array $variant = [], array $product = []): Product
    {
        return $this->shopProduct($variant, $product);
    }

    private function postVideo(Product $product, ?UploadedFile $video = null, array $extra = [])
    {
        return $this->post(self::ADMIN."/products/{$product->id}/videos", ['video' => $video ?? $this->videoFile()] + $extra, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ sold out and featured

    public function test_a_sold_out_product_stays_visible_but_cannot_be_ordered(): void
    {
        $product = $this->product();
        $variant = $this->variantOf($product);
        $this->actingAsAdmin();

        $this->patchJson(self::ADMIN."/products/{$product->id}/sold-out", ['value' => true])->assertOk()->assertJsonPath('data.is_sold_out', true);
        $this->assertTrue(AuditLog::query()->where('action', 'product.sold_out_changed')->exists());

        $card = $this->flushHeaders()->getJson(self::STORE.'/products?per_page=50')->assertOk()->json('data.0');
        $this->assertTrue($card['is_sold_out']);
        $this->assertFalse($card['in_stock']);
        $detail = $this->getJson(self::STORE."/products/{$product->slug}")->assertOk();
        $detail->assertJsonPath('data.is_sold_out', true)->assertJsonPath('data.variants.0.in_stock', false)->assertJsonPath('data.variants.0.qty', 0);
        $this->getJson(self::STORE.'/products?in_stock=1')->assertOk()->assertJsonCount(0, 'data');

        $quote = $this->quote([$variant => 1])->assertOk();
        $quote->assertJsonPath('data.issues.0.code', 'sold_out');
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'cart_invalid');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);   // nothing was taken

        $this->actingAsAdmin();
        $this->patchJson(self::ADMIN."/products/{$product->id}/sold-out", ['value' => false])->assertOk()->assertJsonPath('data.is_sold_out', false);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
    }

    public function test_the_featured_switch_and_the_public_filter(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->actingAsAdmin();

        $this->patchJson(self::ADMIN."/products/{$a->id}/featured", ['value' => true])->assertOk()->assertJsonPath('data.is_featured', true);
        $this->patchJson(self::ADMIN."/products/{$a->id}/featured", [])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->patchJson(self::ADMIN."/products/{$a->id}/featured", ['value' => 'maybe'])->assertStatus(422);

        $res = $this->flushHeaders()->getJson(self::STORE.'/products?featured=1')->assertOk();
        $this->assertSame([$a->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertTrue($res->json('data.0.is_featured'));
        $this->assertCount(2, $this->getJson(self::STORE.'/products')->json('data'));
        $this->assertFalse(collect($this->getJson(self::STORE.'/products')->json('data'))->firstWhere('id', $b->id)['is_featured']);
    }

    public function test_the_switches_need_the_manage_permission_and_the_form_can_set_them_too(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('admin', false);   // the admin's real defaults: no product access at all

        $this->patchJson(self::ADMIN."/products/{$product->id}/sold-out", ['value' => true])->assertForbidden();
        $this->patchJson(self::ADMIN."/products/{$product->id}/featured", ['value' => true])->assertForbidden();
        $this->assertFalse((bool) Product::find($product->id)->is_sold_out);

        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN."/products/{$product->id}", ['is_sold_out' => true, 'is_featured' => true])->assertOk()
            ->assertJsonPath('data.is_sold_out', true)->assertJsonPath('data.is_featured', true);
    }

    public function test_the_admin_list_filters_by_stock_and_featured_and_shows_the_switches(): void
    {
        $in = $this->product(['stock' => 40]);
        $low = $this->product(['stock' => 3, 'low_stock_threshold' => 5]);
        $out = $this->product(['stock' => 0]);
        $sold = $this->product();
        Product::whereKey($sold->id)->update(['is_sold_out' => true, 'is_featured' => true]);
        $this->actingAsAdmin('developer');

        $ids = fn (array $q) => collect($this->getJson(self::ADMIN.'/products?'.http_build_query($q))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$low->id, $out->id], $ids(['stock' => 'low']));   // running low includes nothing left
        $this->assertSame([$out->id], $ids(['stock' => 'out']));
        $this->assertSame([$sold->id], $ids(['stock' => 'sold_out']));
        $this->assertSame([$in->id, $low->id], $ids(['stock' => 'in']));
        $this->assertSame([$sold->id], $ids(['featured' => 1]));
        $this->getJson(self::ADMIN.'/products?stock=nope')->assertStatus(422);

        $row = collect($this->getJson(self::ADMIN.'/products')->json('data'))->firstWhere('id', $sold->id);
        $this->assertTrue($row['is_sold_out']);
        $this->assertTrue($row['is_featured']);
        $this->assertTrue(collect($this->getJson(self::ADMIN.'/products')->json('data'))->firstWhere('id', $low->id)['low_stock']);
    }

    public function test_the_low_stock_alert_level_of_an_option_can_be_set_in_the_form(): void
    {
        $product = $this->product(['stock' => 8]);
        $variant = $product->variants()->first();
        $this->actingAsAdmin('developer');

        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [[
            'id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'mrp' => 200, 'selling_price' => 160, 'stock' => 8, 'low_stock_threshold' => 12,
        ]]])->assertOk()->assertJsonPath('data.variants.0.low_stock_threshold', 12);

        $this->assertSame(1, $this->getJson(self::ADMIN.'/dashboard')->json('catalog.low_stock'));   // 8 left, alert at 12
    }

    // ------------------------------------------------------------------ video

    public function test_a_video_is_uploaded_with_a_poster_and_shown_publicly(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');

        $res = $this->postVideo($product, $this->videoFile('demo.mp4', 40), ['poster' => UploadedFile::fake()->image('poster.jpg', 640, 360)])->assertCreated();
        $video = $res->json('data.0');
        $this->assertSame('video/mp4', $video['mime']);
        $this->assertNotNull($video['poster_url']);
        $this->assertStringContainsString('/storage/', $video['url']);   // the public disk
        $this->assertTrue(AuditLog::query()->where('action', 'product.video_added')->exists());

        $public = $this->flushHeaders()->getJson(self::STORE."/products/{$product->slug}")->assertOk()->json('data.videos');
        $this->assertCount(1, $public);
        $this->assertSame(['url', 'poster_url', 'mime', 'source', 'uploaded_at'], array_keys($public[0]));
        $this->assertNotNull($public[0]['uploaded_at']);
        $this->assertSame('upload', $public[0]['source']);
        $this->assertSame('video/mp4', $public[0]['mime']);
        $this->assertSame($video['url'], $public[0]['url']);

        $this->actingAsAdmin('developer');
        $this->getJson(self::ADMIN."/products/{$product->id}")->assertJsonPath('data.videos.0.id', $video['id'])->assertJsonPath('data.videos.0.name', 'demo.mp4');
    }

    public function test_the_video_content_size_and_count_are_validated(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');

        // a program renamed to .mp4 is refused: the content is checked
        $fake = UploadedFile::fake()->createWithContent('malware.mp4', "MZ\x90\0this is not a video");
        $this->postVideo($product, $fake)->assertStatus(422)->assertJsonValidationErrors('video');
        $this->postVideo($product, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('video');
        $this->post(self::ADMIN."/products/{$product->id}/videos", [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.video.0', 'Choose the video file.');

        config(['shop.video.max_kb' => 100]);
        $this->postVideo($product, $this->videoFile('big.mp4', 150))->assertStatus(422)->assertJsonValidationErrors('video');
        $this->postVideo($product, $this->videoFile('ok.mp4', 90))->assertCreated();

        $this->postVideo($product, $this->videoFile('b.webm', 50), ['poster' => UploadedFile::fake()->create('p.png', 3000, 'image/png')])->assertStatus(422)->assertJsonValidationErrors('poster');
        $this->postVideo($product, $this->videoFile('b.webm', 50))->assertCreated()->assertJsonPath('data.1.mime', 'video/webm');
        $this->postVideo($product, $this->videoFile('c.mp4', 10))->assertStatus(422)->assertJsonValidationErrors('video');   // at most two

        $this->assertSame(2, DB::table('media')->where('collection_name', 'video')->count());
    }

    public function test_a_video_that_php_refused_says_why_and_is_logged(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');
        Log::spy();

        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, 'partial');
        $cases = [
            UPLOAD_ERR_PARTIAL => 'interrupted',
            UPLOAD_ERR_INI_SIZE => 'larger than its limit',
            UPLOAD_ERR_NO_TMP_DIR => 'temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'could not save the file',
        ];
        foreach ($cases as $code => $words) {
            $file = new UploadedFile($path, 'clip.mp4', 'video/mp4', $code, true);
            $this->postVideo($product, $file)->assertStatus(422)->assertJsonPath('errors.video.0', fn ($m) => str_contains($m, $words));
        }
        @unlink($path);

        Log::shouldHaveReceived('warning')->with('Video upload refused', \Mockery::on(fn ($c) => $c['php_error'] === UPLOAD_ERR_PARTIAL && array_key_exists('post_max_size', $c)))->once();
        $this->assertSame(0, DB::table('media')->where('collection_name', 'video')->count());
    }

    public function test_the_panel_is_told_the_real_upload_limit_not_more_than_php_allows(): void
    {
        $this->actingAsAdmin('developer');
        ini_set('memory_limit', '256M');
        $this->assertSame(268435456, ProductVideos::iniBytes('memory_limit'));
        $this->assertSame(0, ProductVideos::iniBytes('no_such_setting'));

        $max = (float) $this->getJson(self::ADMIN.'/products/options')->assertOk()->json('video.max_mb');
        $this->assertLessThanOrEqual(round((int) config('shop.video.max_kb') / 1024, 1), $max);
        $this->assertSame(round(ProductVideos::effectiveMaxKb() / 1024, 1), $max);
    }

    public function test_videos_can_be_reordered_get_a_new_poster_and_be_deleted_with_it(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');
        $a = $this->postVideo($product, $this->videoFile('a.mp4'), ['poster' => UploadedFile::fake()->image('a.jpg', 300, 200)])->json('data.0.id');
        $b = $this->postVideo($product, $this->videoFile('b.mp4'))->json('data.1.id');

        $this->putJson(self::ADMIN."/products/{$product->id}/videos/order", ['ids' => [$b, $a]])->assertOk()->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.id', $a);
        $this->putJson(self::ADMIN."/products/{$product->id}/videos/order", ['ids' => [$b]])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->assertSame([$b, $a], collect($this->flushHeaders()->getJson(self::STORE."/products/{$product->slug}")->json('data.videos'))->map(fn ($v) => basename($v['url']))->map(fn ($n) => $n === 'b.mp4' ? $b : $a)->all());

        $this->actingAsAdmin('developer');
        $this->post(self::ADMIN."/products/{$product->id}/videos/$b/poster", ['poster' => UploadedFile::fake()->image('b.png', 300, 200)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.0.id', $b);
        $this->assertNotNull($this->getJson(self::ADMIN."/products/{$product->id}")->json('data.videos.0.poster_url'));
        $this->post(self::ADMIN."/products/{$product->id}/videos/$b/poster", [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('poster');

        $this->deleteJson(self::ADMIN."/products/{$product->id}/videos/$a")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(0, DB::table('media')->where('collection_name', 'video_poster')->where('custom_properties', 'like', '%"video_id":'.$a.'%')->count());   // its poster went with it
        $this->deleteJson(self::ADMIN."/products/{$product->id}/videos/$a")->assertNotFound();
        $other = $this->product();
        $this->deleteJson(self::ADMIN."/products/{$other->id}/videos/$b")->assertNotFound();   // another product's video
    }

    public function test_the_video_link_is_checked_and_turned_into_an_embed(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');
        $set = fn (?string $url) => $this->putJson(self::ADMIN."/products/{$product->id}", ['video_url' => $url]);

        foreach (['http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://example.com/page', 'javascript:alert(1)', 'https://evil.test/video.exe', 'https://www.youtube.com/'] as $bad) {
            $set($bad)->assertStatus(422)->assertJsonValidationErrors('video_url');
        }

        $set('https://www.youtube.com/watch?v=dQw4w9WgXcQ')->assertOk()->assertJsonPath('data.video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $public = fn () => $this->flushHeaders()->getJson(self::STORE."/products/{$product->slug}")->json('data.videos');
        $this->assertSame([['url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'poster_url' => null, 'mime' => null, 'source' => 'embed']], $public());

        $this->actingAsAdmin('developer');
        $set('https://youtu.be/dQw4w9WgXcQ')->assertOk();
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $public()[0]['url']);
        $this->actingAsAdmin('developer');
        $set('https://vimeo.com/123456789')->assertOk();
        $this->assertSame('https://player.vimeo.com/video/123456789', $public()[0]['url']);
        $this->actingAsAdmin('developer');
        $set('https://cdn.example.com/media/promo.mp4')->assertOk();
        $this->assertSame(['url' => 'https://cdn.example.com/media/promo.mp4', 'poster_url' => null, 'mime' => 'video/mp4', 'source' => 'embed'], $public()[0]);

        // an upload comes first, then the link
        $this->actingAsAdmin('developer');
        $this->postVideo($product)->assertCreated();
        $list = $public();
        $this->assertSame(['upload', 'embed'], array_column($list, 'source'));

        $this->actingAsAdmin('developer');
        $set(null)->assertOk()->assertJsonPath('data.video_url', null);
        $this->assertCount(1, $public());
    }

    public function test_video_endpoints_need_the_manage_permission(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('admin', false);
        $this->postVideo($product)->assertForbidden();
        $this->deleteJson(self::ADMIN."/products/{$product->id}/videos/1")->assertForbidden();
        $this->putJson(self::ADMIN."/products/{$product->id}/videos/order", ['ids' => []])->assertForbidden();
        $this->assertSame(0, DB::table('media')->where('collection_name', 'video')->count());
    }

    public function test_the_product_detail_still_costs_a_fixed_number_of_queries_with_videos(): void
    {
        $product = $this->product();
        $this->actingAsAdmin('developer');
        $this->postVideo($product, null, ['poster' => UploadedFile::fake()->image('p.jpg', 300, 200)])->assertCreated();
        $this->putJson(self::ADMIN."/products/{$product->id}", ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk();

        $plain = $this->product();
        $queries = function (Product $p) {
            $this->flushHeaders()->getJson(self::STORE."/products/{$p->slug}")->assertOk();   // warm the caches
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE."/products/{$p->slug}")->assertOk();

            return count(DB::getQueryLog());
        };
        $this->assertSame($queries($plain), $queries($product), 'videos must not add queries');
    }
}

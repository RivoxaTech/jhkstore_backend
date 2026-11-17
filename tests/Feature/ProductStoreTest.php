<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private const STORE = '/api/v1/store';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedCatalog();
    }

    /** slugs of the products a list call returns, in order */
    private function slugs(string $query = ''): array
    {
        return collect($this->getJson(self::STORE.'/products'.($query ? "?$query" : ''))->assertOk()->json('data'))->pluck('slug')->all();
    }

    // ------------------------------------------------------------------ card shape

    public function test_the_card_has_the_agreed_shape_with_absolute_urls_or_null(): void
    {
        $category = $this->makeCategory('Gadgets');
        $product = $this->makeProduct($category, [
            'name' => 'Prime Pack', 'short_description' => 'A kit', 'badge_text' => 'MADE IN USA', 'mrp' => 4500, 'selling_price' => 2495,
        ], ['hot-selling', 'best-seller']);

        $res = $this->getJson(self::STORE.'/products')->assertOk();
        $card = $res->json('data.0');

        $this->assertSame(['id', 'name', 'slug', 'short_description', 'image_url', 'image_width', 'image_height', 'thumb_url', 'category', 'options_count', 'variant_id', 'min_order_qty', 'has_single_option', 'offers_count', 'show_starting_at', 'badge_label', 'price', 'compare_price', 'discount_label', 'mrp', 'discount_percent',
            'badge_text', 'tags', 'in_stock', 'is_sold_out', 'is_featured', 'availability', 'updated_at'], array_keys($card));
        $this->assertSame('Prime Pack', $card['name']);
        $this->assertSame('prime-pack', $card['slug']);
        $this->assertEquals(2495, $card['price']);
        $this->assertEquals(4500, $card['mrp']);
        $this->assertEquals(44.56, $card['discount_percent']);
        $this->assertSame('MADE IN USA', $card['badge_text']);
        $this->assertEqualsCanonicalizing(['hot-selling', 'best-seller'], $card['tags']);
        $this->assertSame(['name' => 'Gadgets', 'slug' => 'gadgets'], $card['category']);
        $this->assertTrue($card['in_stock']);
        // no picture yet: null everywhere (the frontend shows its placeholder)
        $this->assertNull($card['image_url']);
        $this->assertNull($card['thumb_url']);
        $res->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_uploaded_and_remote_pictures_both_appear_as_absolute_urls(): void
    {
        $category = $this->makeCategory();
        $uploaded = $this->makeProduct($category, ['name' => 'Uploaded']);
        $remote = $this->makeProduct($category, ['name' => 'Remote']);
        $uploaded->addMedia(\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 700, 700))->toMediaCollection('images');
        $uploaded->addMedia(\Illuminate\Http\UploadedFile::fake()->image('b.jpg', 700, 700))->toMediaCollection('images');
        DB::table('product_images')->insert([
            ['product_id' => $remote->id, 'type' => 'image', 'url' => 'https://cdn.example.com/1.jpg', 'sort_order' => 0, 'is_primary' => true],
            ['product_id' => $remote->id, 'type' => 'image', 'url' => 'https://cdn.example.com/2.jpg', 'sort_order' => 1, 'is_primary' => false],
        ]);

        $cards = collect($this->getJson(self::STORE.'/products')->json('data'))->keyBy('name');
        $this->assertStringStartsWith('http', $cards['Uploaded']['image_url']);
        $this->assertStringContainsString('/conversions/', $cards['Uploaded']['image_url']);   // the 512 picture
        $this->assertStringContainsString('/conversions/', $cards['Uploaded']['thumb_url']);   // the 256 picture
        $this->assertSame('https://cdn.example.com/1.jpg', $cards['Remote']['image_url']);

        // detail: one `images` array for both kinds, the first one is primary
        $images = $this->getJson(self::STORE.'/products/'.$uploaded->slug)->assertOk()->json('data.images');
        $this->assertCount(2, $images);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertStringStartsWith('http', $images[1]['url']);
        $remoteImages = $this->getJson(self::STORE.'/products/'.$remote->slug)->json('data.images');
        $this->assertSame(['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg'], array_column($remoteImages, 'url'));
    }

    // ------------------------------------------------------------------ filters and sorting

    public function test_tag_filter_gives_the_homepage_sections(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct($category, ['name' => 'Best One'], ['best-seller']);
        $this->makeProduct($category, ['name' => 'Hot One'], ['hot-selling']);
        $this->makeProduct($category, ['name' => 'Both'], ['best-seller', 'hot-selling', 'new-arrival']);
        $this->makeProduct($category, ['name' => 'Plain']);

        $this->assertEqualsCanonicalizing(['best-one', 'both'], $this->slugs('tag=best-seller'));
        $this->assertEqualsCanonicalizing(['hot-one', 'both'], $this->slugs('tag=hot-selling'));
        $this->assertSame(['both'], $this->slugs('tag=new-arrival'));
        $this->assertSame([], $this->slugs('tag=freebie'));
        $this->assertSame([], $this->slugs('tag=does-not-exist'));
        $this->assertCount(4, $this->slugs());
        $this->assertCount(1, $this->slugs('tag=best-seller&per_page=1'));
    }

    public function test_an_inactive_tag_is_not_a_section_and_is_not_listed(): void
    {
        $product = $this->makeProduct($this->makeCategory(), [], ['best-seller', 'featured']);
        DB::table('tags')->where('slug', 'best-seller')->update(['is_active' => false]);

        $this->assertSame([], $this->slugs('tag=best-seller'));
        $this->assertSame(['featured'], $this->getJson(self::STORE.'/products')->json('data.0.tags'));
        $this->assertNotContains('best-seller', array_column($this->getJson(self::STORE.'/tags')->json('data'), 'slug'));
        $this->assertNotNull($product);
    }

    public function test_category_and_sub_category_filters(): void
    {
        $gadgets = $this->makeCategory('Gadgets');
        $chargers = $this->makeSub($gadgets, 'Chargers');
        $nano = $this->makeSub($gadgets, 'Fast Chargers');
        $endo = $this->makeCategory('Audio');
        $files = $this->makeSub($endo, 'Cables');
        $filesDuplicate = $this->makeSub($gadgets, 'Cables');                          // same slug in another category

        $this->makeProduct($gadgets, ['name' => 'P1', 'sub_category_id' => $chargers->id]);
        $this->makeProduct($gadgets, ['name' => 'P2', 'sub_category_id' => $nano->id]);
        $this->makeProduct($endo, ['name' => 'P3', 'sub_category_id' => $files->id]);
        $this->makeProduct($gadgets, ['name' => 'P4', 'sub_category_id' => $filesDuplicate->id]);

        $this->assertEqualsCanonicalizing(['p1', 'p2', 'p4'], $this->slugs('category=gadgets'));
        $this->assertSame(['p3'], $this->slugs('category=audio'));
        $this->assertSame(['p1'], $this->slugs('sub_category=chargers'));
        $this->assertSame(['p2'], $this->slugs('sub_category=fast-chargers'));
        $this->assertSame(['p3'], $this->slugs('category=audio&sub_category='.$files->slug));            // narrowed by the category
        $this->assertSame(['p4'], $this->slugs('category=gadgets&sub_category='.$filesDuplicate->slug));
        $this->assertSame([], $this->slugs('category=nothing'));
    }

    public function test_search_price_and_stock_filters(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct($category, ['name' => 'Alpha Cable', 'short_description' => 'braided', 'selling_price' => 100, 'mrp' => 100]);
        $this->makeProduct($category, ['name' => 'Beta Adapter', 'selling_price' => 500, 'mrp' => 500, 'sku' => 'BETA-9',
            'stock' => 0]);
        $this->makeProduct($category, ['name' => 'Gamma Dock', 'selling_price' => 900, 'mrp' => 900]);

        $this->assertSame(['beta-adapter'], $this->slugs('q=BETA-9'));                      // exact SKU
        $this->assertSame([], $this->slugs('q=zzz'));

        $this->assertEqualsCanonicalizing(['beta-adapter', 'gamma-dock'], $this->slugs('min_price=200'));
        $this->assertEqualsCanonicalizing(['alpha-cable', 'beta-adapter'], $this->slugs('max_price=500'));
        $this->assertSame(['beta-adapter'], $this->slugs('min_price=200&max_price=500'));

        $this->assertEqualsCanonicalizing(['alpha-cable', 'gamma-dock'], $this->slugs('in_stock=1'));   // beta has no standard stock
        $this->assertCount(3, $this->slugs());                                                                  // out of stock products still list
        $this->getJson(self::STORE.'/products?rating=4')->assertOk();   // the rating filter is gone: an unknown filter is ignored

        $this->getJson(self::STORE.'/products?sort=nonsense')->assertUnprocessable();
        $this->getJson(self::STORE.'/products?per_page=500')->assertUnprocessable();
    }

    public function test_sorting(): void
    {
        $category = $this->makeCategory();
        $a = $this->makeProduct($category, ['name' => 'A', 'mrp' => 1000, 'selling_price' => 900]);   // 10% off
        $b = $this->makeProduct($category, ['name' => 'B', 'mrp' => 400, 'selling_price' => 100]);    // 75% off
        $c = $this->makeProduct($category, ['name' => 'C', 'mrp' => 600, 'selling_price' => 500]);    // 16.67% off
        $a->forceFill(['popularity_score' => 50, 'published_at' => now()->subDays(1)])->save();
        $b->forceFill(['popularity_score' => 10, 'published_at' => now()->subDays(3)])->save();
        $c->forceFill(['popularity_score' => 30, 'published_at' => now()->subDays(2)])->save();

        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=popularity'));
        $this->assertSame(['a', 'c', 'b'], $this->slugs());                    // popularity is the default
        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=newest'));
        $this->assertSame(['b', 'c', 'a'], $this->slugs('sort=price_asc'));
        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=price_desc'));
        $this->assertSame(['b', 'c', 'a'], $this->slugs('sort=discount'));
        $this->getJson(self::STORE.'/products?sort=rating')->assertUnprocessable();   // sorting by rating is gone
    }

    public function test_the_list_is_paginated(): void
    {
        $category = $this->makeCategory();
        foreach (range(1, 5) as $i) {
            $this->makeProduct($category);
        }

        $this->getJson(self::STORE.'/products?per_page=2&page=3')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
    }

    public function test_the_list_uses_a_fixed_number_of_queries(): void
    {
        $category = $this->makeCategory();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE.'/products?per_page=50')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->makeProduct($category, [], ['best-seller']);
        $count();   // the first call also reads the live discounts (kept for the rest of the request)
        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->makeProduct($category, ['variants' => [
                ['name' => 'S', 'sku' => "S$i", 'mrp' => 10, 'selling_price' => 9, 'stock' => 3],
                ['name' => 'L', 'sku' => "L$i", 'mrp' => 20, 'selling_price' => 18, 'stock' => 3],
            ]], ['best-seller', 'featured']);
        }

        $this->assertSame($few, $count());
    }

    // ------------------------------------------------------------------ visibility

    public function test_only_products_that_are_allowed_to_show_are_visible(): void
    {
        $category = $this->makeCategory('Cat');
        $sub = $this->makeSub($category, 'Sub');
        $visible = $this->makeProduct($category, ['name' => 'Visible', 'sub_category_id' => $sub->id]);

        $draft = $this->makeProduct($category, ['name' => 'Draft', 'status' => 'draft']);
        $inactive = $this->makeProduct($category, ['name' => 'Inactive', 'status' => 'inactive']);
        $archived = $this->makeProduct($category, ['name' => 'Archived', 'status' => 'archived']);
        $deleted = $this->makeProduct($category, ['name' => 'Deleted']);
        $deleted->delete();

        $offCategory = $this->makeCategory('Off Cat');
        $this->makeProduct($offCategory, ['name' => 'Category off']);
        $offCategory->update(['is_active' => false]);

        $offSub = $this->makeSub($category, 'Off Sub');
        $this->makeProduct($category, ['name' => 'Sub off', 'sub_category_id' => $offSub->id]);
        $offSub->update(['is_active' => false]);

        $noOption = $this->makeProduct($category, ['name' => 'No active option']);
        DB::table('product_variants')->where('product_id', $noOption->id)->update(['is_active' => false]);

        $this->assertSame(['visible'], $this->slugs());
        foreach ([$draft, $inactive, $archived, $deleted, $noOption] as $hidden) {
            $this->getJson(self::STORE.'/products/'.$hidden->slug)->assertNotFound();
        }
        $this->getJson(self::STORE.'/products/'.$visible->slug)->assertOk();

        // and the same product is shown again when everything is switched back on
        $offCategory->update(['is_active' => true]);
        $this->assertContains('category-off', $this->slugs());
    }

    public function test_out_of_stock_products_still_list_with_in_stock_false(): void
    {
        $this->makeProduct($this->makeCategory(), ['stock' => 0]);

        $res = $this->getJson(self::STORE.'/products')->assertOk();
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.in_stock', false);
    }

    // ------------------------------------------------------------------ stock

    public function test_reserved_units_are_not_counted_as_available(): void
    {
        $product = $this->makeProduct($this->makeCategory(), ['stock' => 5]);
        DB::table('product_variants')->update(['reserved_qty' => 5]);

        $this->getJson(self::STORE.'/products/'.$product->slug)->assertJsonPath('data.variants.0.qty', 0)->assertJsonPath('data.in_stock', false);
    }

    // ------------------------------------------------------------------ detail

    public function test_product_detail(): void
    {
        $category = $this->makeCategory('Gadgets');
        $sub = $this->makeSub($category, 'Chargers');
        $product = $this->makeProduct($category, [
            'name' => 'Mittens', 'sku' => 'GLV', 'sub_category_id' => $sub->id, 'description' => 'Long text',
            'max_order_qty' => 5,
            'variants' => [
                ['name' => 'Small', 'sku' => 'GLV-S', 'mrp' => 500, 'selling_price' => 400, 'stock' => 10],
                ['name' => 'Large', 'sku' => 'GLV-L', 'mrp' => 600, 'selling_price' => 300, 'stock' => 0],
            ],
            'content_tabs' => [['tab_key' => 'specifications', 'content' => '1 year'], ['tab_key' => 'features', 'format' => 'bullets', 'content' => "Soft\nStrong"]],
        ], ['featured']);
        $related = $this->makeProduct($category, ['name' => 'Related One']);

        $res = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk();
        $d = $res->json('data');

        $this->assertSame('Mittens', $d['name']);
        $this->assertSame('Long text', $d['description']);
        $this->assertSame(5, $d['max_order_qty']);
        $this->assertSame(['name' => 'Chargers', 'slug' => 'chargers'], $d['sub_category']);
        $this->assertEquals(300, $d['price']);                                  // the card shows the cheapest variant
        $this->assertEquals(600, $d['mrp']);
        $this->assertTrue($d['in_stock']);
        $this->assertSame(['features', 'specifications'], array_column($d['content_tabs'], 'key'));   // in the tab order
        $this->assertSame('Features', $d['content_tabs'][0]['title']);
        $this->assertSame('bullets', $d['content_tabs'][0]['format']);

        $this->assertCount(2, $d['variants']);
        $this->assertSame(['id', 'name', 'sku', 'is_default', 'mrp', 'price', 'compare_price', 'discount_label', 'option_price', 'discount_percent', 'is_cod_available',
            'in_stock', 'qty', 'low_stock', 'price_tiers', 'image_url', 'thumb_url', 'images'], array_keys($d['variants'][0]));
        $this->assertSame('Small', $d['variants'][0]['name']);
        $this->assertEquals(400, $d['variants'][0]['price']);
        $this->assertEquals(20, $d['variants'][0]['discount_percent']);
        $this->assertTrue($d['variants'][0]['in_stock']);
        $this->assertSame(10, $d['variants'][0]['qty']);
        $this->assertFalse($d['variants'][1]['in_stock']);

        $this->assertSame([], $d['images']);
        $this->assertSame(['related-one'], array_column($d['related'], 'slug'));                   // same category fallback
        $this->assertNotNull($related);
        $this->getJson(self::STORE.'/products/not-a-product')->assertNotFound();
    }

    public function test_hand_picked_related_products_win_over_the_category_fallback(): void
    {
        $category = $this->makeCategory();
        $main = $this->makeProduct($category, ['name' => 'Main']);
        $this->makeProduct($category, ['name' => 'Same Category']);
        $picked = $this->makeProduct($this->makeCategory('Other'), ['name' => 'Picked']);
        $hidden = $this->makeProduct($category, ['name' => 'Hidden Pick', 'status' => 'draft']);
        DB::table('product_relations')->insert([
            ['product_id' => $main->id, 'related_product_id' => $picked->id, 'type' => 'related', 'sort_order' => 1],
            ['product_id' => $main->id, 'related_product_id' => $hidden->id, 'type' => 'related', 'sort_order' => 2],
        ]);

        $related = $this->getJson(self::STORE.'/products/main')->json('data.related');
        $this->assertSame(['picked'], array_column($related, 'slug'));      // hidden products never leak through
    }

    public function test_the_detail_page_does_not_run_queries_per_variant(): void
    {
        $category = $this->makeCategory();
        $variants = fn (int $n) => array_map(fn ($i) => ['name' => "V$i", 'sku' => "V$i-".$n, 'mrp' => 10, 'selling_price' => 9, 'stock' => 2], range(1, $n));
        $few = $this->makeProduct($category, ['name' => 'Few', 'variants' => $variants(1)]);
        $many = $this->makeProduct($category, ['name' => 'Many', 'variants' => $variants(8)], ['best-seller']);
        $count = function (string $slug) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE.'/products/'.$slug)->assertOk();

            return count(DB::getQueryLog());
        };

        $count($few->slug);   // the first call also reads the live discounts (kept for the rest of the request)
        $this->assertSame($count($few->slug), $count($many->slug));
    }

    // ------------------------------------------------------------------ tags

    public function test_public_tags_are_the_active_ones(): void
    {
        DB::table('tags')->where('slug', 'freebie')->update(['is_active' => false]);

        $res = $this->getJson(self::STORE.'/tags')->assertOk();
        $res->assertJsonCount(6, 'data');
        $this->assertSame(['name', 'slug', 'color', 'style'], array_keys($res->json('data.0')));
        $this->assertSame('best-seller', $res->json('data.0.slug'));
    }

    // ------------------------------------------------------------------ product page

    public function test_the_product_page_has_option_pictures_quantity_prices_and_delivery(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, [
            'name' => 'Travel Bottle', 'meta_title' => 'Bottle', 'meta_description' => 'Pure bottle',
            'variants' => [
                ['name' => 'Travel Bottle (15ml) (15)', 'sku' => '3002-015', 'mrp' => 190, 'selling_price' => 160,
                    'price_tiers' => [['min_qty' => 2, 'price' => 155], ['min_qty' => 5, 'price' => 152]], 'stock' => 3],
                ['name' => 'Travel Bottle (30ml) (30)', 'sku' => '3002-030', 'mrp' => 330, 'selling_price' => 210, 'stock' => 40],
            ],
        ]);
        [$small, $large] = $product->variants()->get()->all();
        $small->update(['is_cod_available' => false]);
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('main.jpg', 700, 700))->toMediaCollection('images');
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v.jpg', 700, 700))->withCustomProperties(['variant_id' => $small->id])->toMediaCollection('images');
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v2.jpg', 700, 700))->withCustomProperties(['variant_id' => $small->id])->toMediaCollection('images');

        $d = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->json('data');

        // different names per option
        $this->assertSame(['Travel Bottle (15ml) (15)', 'Travel Bottle (30ml) (30)'], array_column($d['variants'], 'name'));
        // quantity prices with the extra discount
        $this->assertEquals([['min_qty' => 2, 'price' => 155, 'extra_discount_percent' => 3.13], ['min_qty' => 5, 'price' => 152, 'extra_discount_percent' => 5]],
            array_map(fn ($t) => ['min_qty' => $t['min_qty'], 'price' => (int) $t['price'], 'extra_discount_percent' => (float) $t['extra_discount_percent']], $d['variants'][0]['price_tiers']));
        $this->assertSame([], $d['variants'][1]['price_tiers']);
        // the option's own pictures, and the fallback to the product's primary picture
        $this->assertCount(2, $d['variants'][0]['images']);
        $this->assertTrue($d['variants'][0]['images'][0]['is_primary']);
        $this->assertSame($d['variants'][0]['images'][0]['medium_url'], $d['variants'][0]['image_url']);
        $this->assertSame([], $d['variants'][1]['images']);
        $this->assertSame($d['images'][0]['medium_url'], $d['variants'][1]['image_url']);
        // the product gallery holds only the product's own picture
        $this->assertCount(1, $d['images']);
        $this->assertArrayNotHasKey('variant_id', $d['images'][0]);
        // card picture = the product's own primary picture
        $this->assertSame($d['images'][0]['medium_url'], $this->getJson(self::STORE.'/products')->json('data.0.image_url'));
        // low stock, cod override, delivery, meta
        $this->assertTrue($d['variants'][0]['low_stock']);                // 3 left, threshold 5
        $this->assertFalse($d['variants'][1]['low_stock']);
        $this->assertFalse($d['variants'][0]['is_cod_available']);        // the option overrides the product
        $this->assertTrue($d['variants'][1]['is_cod_available']);
        $this->assertSame(['days' => 5, 'arrives_by' => now()->addDays(5)->toDateString()], $d['delivery']);
        $this->assertSame('Bottle', $d['meta_title']);

        // the delivery time is a setting
        app(\App\Domain\Support\Settings::class)->set(['delivery_days' => 2]);
        $this->assertSame(2, $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.delivery.days'));
    }

    public function test_a_product_is_a_parent_of_its_options_and_starts_at_the_cheapest_one(): void
    {
        $category = $this->makeCategory();
        $gun = $this->makeProduct($category, ['name' => 'Glock', 'variants' => [
            ['name' => 'Glock 17', 'sku' => 'G17', 'mrp' => 900, 'selling_price' => 800, 'stock' => 2],
            ['name' => 'Glock 19', 'sku' => 'G19', 'mrp' => 700, 'selling_price' => 600, 'stock' => 0],
            ['name' => 'Glock 26', 'sku' => 'G26', 'mrp' => 800, 'selling_price' => 650, 'stock' => 5],
        ]]);
        $single = $this->makeProduct($category, ['name' => 'Single Gun']);

        $cards = collect($this->getJson(self::STORE.'/products')->json('data'))->keyBy('name');
        $this->assertSame(3, $cards['Glock']['options_count']);
        $this->assertEquals(600, $cards['Glock']['price']);              // starting price = the cheapest option, even though it is sold out
        $this->assertTrue($cards['Glock']['in_stock']);                  // another option has stock
        $this->assertSame(1, $cards['Single Gun']['options_count']);

        // even a product with one option lists it as an option, with its own name
        $d = $this->getJson(self::STORE.'/products/'.$single->slug)->json('data');
        $this->assertCount(1, $d['variants']);
        $this->assertSame('Single Gun', $d['variants'][0]['name']);
        $this->assertSame(['Glock 17', 'Glock 19', 'Glock 26'], array_column($this->getJson(self::STORE.'/products/'.$gun->slug)->json('data.variants'), 'name'));
    }

    public function test_a_single_option_product_shows_its_offers_or_one_option_and_a_direct_price(): void
    {
        $category = $this->makeCategory();
        $offers = $this->makeProduct($category, ['name' => 'Phone Case', 'variants' => [
            ['name' => 'Phone Case NC Compatible', 'sku' => 'SB', 'mrp' => 1490, 'selling_price' => 840,
                'price_tiers' => [['min_qty' => 2, 'price' => 820], ['min_qty' => 5, 'price' => 800], ['min_qty' => 10, 'price' => 760]], 'stock' => 3],
        ]]);
        $this->makeProduct($category, ['name' => 'Plain Gun']);
        $this->makeProduct($category, ['name' => 'Glock', 'variants' => [
            ['name' => 'Glock 17', 'sku' => 'G17', 'mrp' => 900, 'selling_price' => 800, 'price_tiers' => [['min_qty' => 2, 'price' => 790]], 'stock' => 2],
            ['name' => 'Glock 19', 'sku' => 'G19', 'mrp' => 700, 'selling_price' => 600, 'stock' => 1],
        ]]);

        $cards = collect($this->getJson(self::STORE.'/products')->json('data'))->keyBy('slug');

        // one option with bulk prices: its name, a direct price and "3 Offers"
        $c = $cards['phone-case'];
        $this->assertTrue($c['has_single_option']);
        $this->assertFalse($c['show_starting_at']);
        $this->assertSame(3, $c['offers_count']);
        $this->assertSame('3 Offers', $c['badge_label']);
        $this->assertSame('Phone Case NC Compatible', $c['name']);
        $this->assertEquals(840, $c['price']);

        // one option without offers: "1 Option"
        $this->assertSame('1 Option', $cards['plain-gun']['badge_label']);
        $this->assertFalse($cards['plain-gun']['show_starting_at']);

        // several options: the parent's name, "Starting at" and the number of options, even when one option has offers
        $m = $cards['glock'];
        $this->assertFalse($m['has_single_option']);
        $this->assertTrue($m['show_starting_at']);
        $this->assertSame(0, $m['offers_count']);
        $this->assertSame('2 Options', $m['badge_label']);
        $this->assertSame('Glock', $m['name']);

        // the detail page: a single option leads with the option; several options keep the parent
        $d = $this->getJson(self::STORE.'/products/'.$offers->slug)->assertOk()->json('data');
        $this->assertSame('single_option', $d['display_mode']);
        $this->assertSame('Phone Case NC Compatible', $d['name']);
        $this->assertSame('Phone Case', $d['product_name']);
        $this->assertSame('SB', $d['sku']);
        $this->assertSame('multiple_options', $this->getJson(self::STORE.'/products/glock')->json('data.display_mode'));
        $this->assertSame('Glock', $this->getJson(self::STORE.'/products/glock')->json('data.name'));
    }

    public function test_every_option_has_its_own_stock(): void
    {
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Shirt', 'variants' => [
            ['name' => 'Shirt Red', 'sku' => 'S-R', 'mrp' => 10, 'selling_price' => 9, 'stock' => 3],
            ['name' => 'Shirt Blue', 'sku' => 'S-B', 'mrp' => 10, 'selling_price' => 9, 'stock' => 10],
        ]]);

        $variants = $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.variants');
        $this->assertSame([3, 10], array_column($variants, 'qty'));
        $this->assertSame([true, false], array_column($variants, 'low_stock'));   // red: 3 left
        $this->assertSame([true, true], array_column($variants, 'in_stock'));
    }

    public function test_an_inactive_option_is_not_sold_and_a_product_without_active_options_is_hidden(): void
    {
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Pen', 'variants' => [
            ['name' => 'Pen Black', 'sku' => 'P-K', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'Pen Blue', 'sku' => 'P-B', 'mrp' => 12, 'selling_price' => 11],
        ]]);
        $black = $product->variants()->where('sku', 'P-K')->first();
        $black->update(['is_active' => false]);

        $this->assertSame(['Pen Blue'], array_column($this->getJson(self::STORE.'/products/'.$product->slug)->json('data.variants'), 'name'));
        $this->assertEquals(11, $this->getJson(self::STORE.'/products')->json('data.0.price'));

        $product->variants()->update(['is_active' => false]);
        $this->assertSame([], $this->slugs());
        $this->getJson(self::STORE.'/products/'.$product->slug)->assertNotFound();
    }

    public function test_when_only_the_options_have_pictures_the_gallery_and_cards_use_them(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v.jpg', 700, 700))
            ->withCustomProperties(['variant_id' => $product->variants()->value('id')])->toMediaCollection('images');

        $this->assertCount(1, $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.images'));
        $this->assertStringContainsString('/conversions/', $this->getJson(self::STORE.'/products')->json('data.0.image_url'));
    }

    public function test_the_currency_comes_from_the_settings(): void
    {
        $this->getJson(self::STORE.'/settings')->assertOk()->assertJsonPath('currency.code', 'USD')->assertJsonPath('currency.symbol', '$');
    }
}

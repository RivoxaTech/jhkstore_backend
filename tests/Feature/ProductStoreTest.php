<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\Product;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Review\Models\ProductRatingStat;
use App\Domain\Review\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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

    private function customer(string $email = 'buyer@example.test', string $name = 'Asha Verma'): User
    {
        return User::create(['full_name' => $name, 'email' => $email, 'mobile' => '98'.random_int(10000000, 99999999), 'password' => 'secret', 'is_active' => true]);
    }

    /** slugs of the products a list call returns, in order */
    private function slugs(string $query = ''): array
    {
        return collect($this->getJson(self::STORE.'/products'.($query ? "?$query" : ''))->assertOk()->json('data'))->pluck('slug')->all();
    }

    // ------------------------------------------------------------------ card shape

    public function test_the_card_has_the_agreed_shape_with_absolute_urls_or_null(): void
    {
        $company = $this->makeCompany('Acme');
        $category = $this->makeCategory('Restorative');
        $product = $this->makeProduct($company, $category, [
            'name' => 'Prime Kit', 'short_description' => 'A kit', 'badge_text' => 'MADE IN USA', 'mrp' => 4500, 'selling_price' => 2495, 'reward_points' => 25,
        ], ['hot-selling', 'best-seller']);
        $brandId = $product->brand_id;

        $res = $this->getJson(self::STORE.'/products')->assertOk();
        $card = $res->json('data.0');

        $this->assertSame(['id', 'name', 'slug', 'short_description', 'image_url', 'thumb_url', 'brand', 'category', 'options_count', 'has_single_option', 'offers_count', 'show_starting_at', 'badge_label', 'price', 'mrp', 'discount_percent',
            'reward_points', 'rating_avg', 'rating_count', 'badge_text', 'tags', 'in_stock'], array_keys($card));
        $this->assertSame(['name', 'slug', 'logo_thumb_url'], array_keys($card['brand']));
        $this->assertSame('Prime Kit', $card['name']);
        $this->assertSame('prime-kit', $card['slug']);
        $this->assertEquals(2495, $card['price']);
        $this->assertEquals(4500, $card['mrp']);
        $this->assertEquals(44.56, $card['discount_percent']);
        $this->assertSame(25, $card['reward_points']);
        $this->assertSame('MADE IN USA', $card['badge_text']);
        $this->assertEqualsCanonicalizing(['hot-selling', 'best-seller'], $card['tags']);
        $this->assertSame(['name' => 'Restorative', 'slug' => 'restorative'], $card['category']);
        $this->assertSame('Acme Brand', $card['brand']['name']);
        $this->assertTrue($card['in_stock']);
        // no picture and no company logo yet: null everywhere (the frontend shows its placeholder)
        $this->assertNull($card['image_url']);
        $this->assertNull($card['thumb_url']);
        $this->assertNull($card['brand']['logo_thumb_url']);
        $this->assertSame($brandId, $product->brand_id);
        $res->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_uploaded_and_remote_pictures_both_appear_as_absolute_urls(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $uploaded = $this->makeProduct($company, $category, ['name' => 'Uploaded']);
        $remote = $this->makeProduct($company, $category, ['name' => 'Remote']);
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

    public function test_the_brand_logo_falls_back_to_the_company_logo(): void
    {
        $company = $this->makeCompany();
        $company->addMedia(\Illuminate\Http\UploadedFile::fake()->image('logo.png', 300, 300))->toMediaCollection('logo');
        $this->makeProduct($company, $this->makeCategory());

        $logo = $this->getJson(self::STORE.'/products')->json('data.0.brand.logo_thumb_url');
        $this->assertStringStartsWith('http', $logo);
    }

    // ------------------------------------------------------------------ filters and sorting

    public function test_tag_filter_gives_the_homepage_sections(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $this->makeProduct($company, $category, ['name' => 'Best One'], ['best-seller']);
        $this->makeProduct($company, $category, ['name' => 'Hot One'], ['hot-selling']);
        $this->makeProduct($company, $category, ['name' => 'Both'], ['best-seller', 'hot-selling', 'new-arrival']);
        $this->makeProduct($company, $category, ['name' => 'Plain']);

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
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), [], ['best-seller', 'featured']);
        DB::table('tags')->where('slug', 'best-seller')->update(['is_active' => false]);

        $this->assertSame([], $this->slugs('tag=best-seller'));
        $this->assertSame(['featured'], $this->getJson(self::STORE.'/products')->json('data.0.tags'));
        $this->assertNotContains('best-seller', array_column($this->getJson(self::STORE.'/tags')->json('data'), 'slug'));
        $this->assertNotNull($product);
    }

    public function test_category_sub_category_and_brand_filters(): void
    {
        $acme = $this->makeCompany('Acme');
        $restorative = $this->makeCategory('Restorative');
        $composites = $this->makeSub($restorative, 'Composites');
        $nano = $this->makeSub($restorative, 'Nano Composites', parent: $composites);   // a sub-sub-category
        $endo = $this->makeCategory('Endo');
        $files = $this->makeSub($endo, 'Files');
        $filesDuplicate = $this->makeSub($restorative, 'Files');                          // same slug in another category
        $waldent = $this->makeBrand($acme, 'Waldent');

        $this->makeProduct($acme, $restorative, ['name' => 'P1', 'sub_category_id' => $composites->id]);
        $this->makeProduct($acme, $restorative, ['name' => 'P2', 'sub_category_id' => $nano->id, 'brand_id' => $waldent->id]);
        $this->makeProduct($acme, $endo, ['name' => 'P3', 'sub_category_id' => $files->id]);
        $this->makeProduct($acme, $restorative, ['name' => 'P4', 'sub_category_id' => $filesDuplicate->id]);

        $this->assertEqualsCanonicalizing(['p1', 'p2', 'p4'], $this->slugs('category=restorative'));
        $this->assertSame(['p3'], $this->slugs('category=endo'));
        $this->assertEqualsCanonicalizing(['p1', 'p2'], $this->slugs('sub_category=composites'));        // a parent includes its children
        $this->assertSame(['p2'], $this->slugs('sub_category=nano-composites'));
        $this->assertSame(['p3'], $this->slugs('category=endo&sub_category='.$files->slug));            // narrowed by the category
        $this->assertSame(['p4'], $this->slugs('category=restorative&sub_category='.$filesDuplicate->slug));
        $this->assertSame(['p2'], $this->slugs('brand=waldent'));                                      // brand page
        $this->assertCount(3, $this->slugs('brand=acme-brand'));
        $this->assertSame([], $this->slugs('category=nothing'));
        $this->assertSame([], $this->slugs('brand=nothing'));
    }

    public function test_search_price_stock_and_rating_filters(): void
    {
        $acme = $this->makeCompany('Acme');
        $category = $this->makeCategory();
        $waldent = $this->makeBrand($acme, 'Waldent');
        $this->makeProduct($acme, $category, ['name' => 'Alpha Composite', 'short_description' => 'light cured', 'selling_price' => 100, 'mrp' => 100]);
        $this->makeProduct($acme, $category, ['name' => 'Beta File', 'selling_price' => 500, 'mrp' => 500, 'brand_id' => $waldent->id, 'sku' => 'BETA-9',
            'stock' => ['standard' => 0, 'instadent' => 4]]);
        $this->makeProduct($acme, $category, ['name' => 'Gamma Cement', 'selling_price' => 900, 'mrp' => 900]);
        Product::where('name', 'Alpha Composite')->update(['rating_avg' => 4.5, 'rating_count' => 10]);
        Product::where('name', 'Gamma Cement')->update(['rating_avg' => 3.0, 'rating_count' => 2]);

        $this->assertSame(['beta-file'], $this->slugs('q=BETA-9'));                      // exact SKU
        $this->assertSame(['beta-file'], $this->slugs('q=waldent'));                     // brand name
        $this->assertSame([], $this->slugs('q=zzz'));

        $this->assertEqualsCanonicalizing(['beta-file', 'gamma-cement'], $this->slugs('min_price=200'));
        $this->assertEqualsCanonicalizing(['alpha-composite', 'beta-file'], $this->slugs('max_price=500'));
        $this->assertSame(['beta-file'], $this->slugs('min_price=200&max_price=500'));

        $this->assertEqualsCanonicalizing(['alpha-composite', 'gamma-cement'], $this->slugs('in_stock=1'));   // beta has no standard stock
        $this->assertCount(3, $this->slugs());                                                                  // out of stock products still list
        $this->assertSame(['alpha-composite'], $this->slugs('rating=4'));
        $this->assertEqualsCanonicalizing(['alpha-composite', 'gamma-cement'], $this->slugs('rating=3'));

        $this->getJson(self::STORE.'/products?sort=nonsense')->assertUnprocessable();
        $this->getJson(self::STORE.'/products?per_page=500')->assertUnprocessable();
        $this->getJson(self::STORE.'/products?rating=9')->assertUnprocessable();
    }

    public function test_sorting(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $a = $this->makeProduct($company, $category, ['name' => 'A', 'mrp' => 1000, 'selling_price' => 900]);   // 10% off
        $b = $this->makeProduct($company, $category, ['name' => 'B', 'mrp' => 400, 'selling_price' => 100]);    // 75% off
        $c = $this->makeProduct($company, $category, ['name' => 'C', 'mrp' => 600, 'selling_price' => 500]);    // 16.67% off
        $a->forceFill(['popularity_score' => 50, 'rating_avg' => 3.5, 'rating_count' => 4, 'published_at' => now()->subDays(1)])->save();
        $b->forceFill(['popularity_score' => 10, 'rating_avg' => 4.9, 'rating_count' => 8, 'published_at' => now()->subDays(3)])->save();
        $c->forceFill(['popularity_score' => 30, 'rating_avg' => 4.0, 'rating_count' => 2, 'published_at' => now()->subDays(2)])->save();

        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=popularity'));
        $this->assertSame(['a', 'c', 'b'], $this->slugs());                    // popularity is the default
        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=newest'));
        $this->assertSame(['b', 'c', 'a'], $this->slugs('sort=price_asc'));
        $this->assertSame(['a', 'c', 'b'], $this->slugs('sort=price_desc'));
        $this->assertSame(['b', 'c', 'a'], $this->slugs('sort=discount'));
        $this->assertSame(['b', 'c', 'a'], $this->slugs('sort=rating'));
    }

    public function test_the_list_is_paginated(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        foreach (range(1, 5) as $i) {
            $this->makeProduct($company, $category);
        }

        $this->getJson(self::STORE.'/products?per_page=2&page=3')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
    }

    public function test_the_list_uses_a_fixed_number_of_queries(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE.'/products?per_page=50')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->makeProduct($company, $category, [], ['best-seller']);
        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->makeProduct($company, $category, ['variants' => [
                ['name' => 'S', 'sku' => "S$i", 'mrp' => 10, 'selling_price' => 9, 'stock' => ['standard' => 3]],
                ['name' => 'L', 'sku' => "L$i", 'mrp' => 20, 'selling_price' => 18, 'stock' => ['standard' => 3]],
            ]], ['best-seller', 'featured']);
        }

        $this->assertSame($few, $count());
    }

    // ------------------------------------------------------------------ visibility

    public function test_only_products_that_are_allowed_to_show_are_visible(): void
    {
        $company = $this->makeCompany('Acme');
        $category = $this->makeCategory('Cat');
        $sub = $this->makeSub($category, 'Sub');
        $child = $this->makeSub($category, 'Child', parent: $sub);
        $visible = $this->makeProduct($company, $category, ['name' => 'Visible', 'sub_category_id' => $child->id]);

        $draft = $this->makeProduct($company, $category, ['name' => 'Draft', 'status' => 'draft']);
        $inactive = $this->makeProduct($company, $category, ['name' => 'Inactive', 'status' => 'inactive']);
        $archived = $this->makeProduct($company, $category, ['name' => 'Archived', 'status' => 'archived']);
        $deleted = $this->makeProduct($company, $category, ['name' => 'Deleted']);
        $deleted->delete();

        $sleepingBrand = $this->makeBrand($company, 'Sleeping');
        $this->makeProduct($company, $category, ['name' => 'Brand off', 'brand_id' => $sleepingBrand->id]);
        $sleepingBrand->update(['is_active' => false]);

        $suspended = $this->makeCompany('Suspended Co');
        $this->makeProduct($suspended, $category, ['name' => 'Company suspended']);
        $suspended->forceFill(['status' => CompanyStatus::Suspended])->save();

        $offCategory = $this->makeCategory('Off Cat');
        $this->makeProduct($company, $offCategory, ['name' => 'Category off']);
        $offCategory->update(['is_active' => false]);

        $offSub = $this->makeSub($category, 'Off Sub');
        $this->makeProduct($company, $category, ['name' => 'Sub off', 'sub_category_id' => $offSub->id]);
        $offSub->update(['is_active' => false]);

        $parent = $this->makeSub($category, 'Parent');
        $kid = $this->makeSub($category, 'Kid', parent: $parent);
        $this->makeProduct($company, $category, ['name' => 'Parent off', 'sub_category_id' => $kid->id]);
        $parent->update(['is_active' => false]);

        $standardOff = $this->makeProduct($company, $category, ['name' => 'Channel off']);
        $standardOff->channels()->updateExistingPivot(DB::table('sales_channels')->where('code', 'standard')->value('id'), ['is_active' => false]);

        $this->assertSame(['visible'], $this->slugs());
        foreach ([$draft, $inactive, $archived, $deleted, $standardOff] as $hidden) {
            $this->getJson(self::STORE.'/products/'.$hidden->slug)->assertNotFound();
        }
        $this->getJson(self::STORE.'/products/'.$visible->slug)->assertOk();

        // and the same product is shown again when everything is switched back on
        $offCategory->update(['is_active' => true]);
        $this->assertContains('category-off', $this->slugs());
    }

    public function test_a_pending_company_product_is_hidden_until_the_company_is_approved(): void
    {
        $category = $this->makeCategory();
        $company = $this->makeCompany('Waiting Co');                    // approved, so the product can be saved
        $this->makeProduct($company, $category, ['name' => 'Waiting product']);
        $company->forceFill(['status' => CompanyStatus::Pending])->save();
        $this->assertSame([], $this->slugs());

        $company->forceFill(['status' => CompanyStatus::Approved])->save();
        $this->assertSame(['waiting-product'], $this->slugs());
    }

    public function test_out_of_stock_products_still_list_with_in_stock_false(): void
    {
        $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['stock' => ['standard' => 0, 'instadent' => 0]]);

        $res = $this->getJson(self::STORE.'/products')->assertOk();
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.in_stock', false);
    }

    // ------------------------------------------------------------------ channels

    public function test_instadent_needs_a_login(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());

        $this->getJson(self::STORE.'/products?channel=instadent')->assertUnauthorized();
        $this->getJson(self::STORE.'/products/'.$product->slug.'?channel=instadent')->assertUnauthorized();
        // standard stays open to guests
        $this->getJson(self::STORE.'/products?channel=standard')->assertOk();
        $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk();
        // an unknown channel
        $this->getJson(self::STORE.'/products?channel=amazon')->assertUnprocessable();
    }

    public function test_an_admin_token_does_not_open_instadent(): void
    {
        $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $this->actingAsAdmin();

        $this->getJson(self::STORE.'/products?channel=instadent')->assertUnauthorized();
    }

    public function test_a_logged_in_customer_sees_instadent_products_and_their_own_stock(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $both = $this->makeProduct($company, $category, ['name' => 'Both', 'stock' => ['standard' => 0, 'instadent' => 6]]);
        $this->makeProduct($company, $category, ['name' => 'Standard only', 'channels' => [['code' => 'standard', 'is_active' => true]]]);
        $this->makeProduct($company, $category, ['name' => 'Instadent only', 'channels' => [['code' => 'instadent', 'is_active' => true]], 'stock' => ['instadent' => 9]]);
        $off = $this->makeProduct($company, $category, ['name' => 'Instadent switched off']);
        $off->channels()->updateExistingPivot(DB::table('sales_channels')->where('code', 'instadent')->value('id'), ['is_active' => false]);

        // guests: the standard catalogue
        $this->assertEqualsCanonicalizing(['both', 'standard-only', 'instadent-switched-off'], $this->slugs());

        Sanctum::actingAs($this->customer());
        $this->assertEqualsCanonicalizing(['both', 'instadent-only'], $this->slugs('channel=instadent'));
        $this->assertEqualsCanonicalizing(['both', 'standard-only', 'instadent-switched-off'], $this->slugs('channel=standard'));

        // stock is separate per channel: "Both" has no standard stock but 6 on Instadent
        $standard = $this->getJson(self::STORE.'/products/both')->assertOk();
        $standard->assertJsonPath('data.in_stock', false)->assertJsonPath('data.variants.0.qty', 0)->assertJsonPath('data.variants.0.in_stock', false);
        $instadent = $this->getJson(self::STORE.'/products/both?channel=instadent')->assertOk();
        $instadent->assertJsonPath('data.in_stock', true)->assertJsonPath('data.variants.0.qty', 6)->assertJsonPath('data.variants.0.in_stock', true);
        $this->assertEqualsCanonicalizing(['both', 'instadent-only'], $this->slugs('channel=instadent&in_stock=1'));
        $this->getJson(self::STORE.'/products/instadent-only')->assertNotFound();                // not on standard
        $this->getJson(self::STORE.'/products/standard-only?channel=instadent')->assertNotFound();
        $this->assertNotNull($both);
    }

    public function test_reserved_units_are_not_counted_as_available(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['stock' => ['standard' => 5]]);
        DB::table('variant_channel_stock')->update(['reserved_qty' => 5]);

        $this->getJson(self::STORE.'/products/'.$product->slug)->assertJsonPath('data.variants.0.qty', 0)->assertJsonPath('data.in_stock', false);
    }

    // ------------------------------------------------------------------ detail

    public function test_product_detail(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory('Restorative');
        $sub = $this->makeSub($category, 'Composites');
        $product = $this->makeProduct($company, $category, [
            'name' => 'Gloves', 'sku' => 'GLV', 'sub_category_id' => $sub->id, 'description' => 'Long text', 'country_of_origin' => 'Japan',
            'is_returnable' => false, 'max_order_qty' => 5,
            'variants' => [
                ['name' => 'Small', 'sku' => 'GLV-S', 'mrp' => 500, 'selling_price' => 400, 'reward_points' => 4, 'expiry_date' => '2030-01-31', 'stock' => ['standard' => 10]],
                ['name' => 'Large', 'sku' => 'GLV-L', 'mrp' => 600, 'selling_price' => 300, 'reward_points' => 6, 'stock' => ['standard' => 0]],
            ],
            'content_tabs' => [['tab_key' => 'warranty', 'content' => '1 year'], ['tab_key' => 'features', 'format' => 'bullets', 'content' => "Soft\nStrong"]],
        ], ['featured']);
        $related = $this->makeProduct($company, $category, ['name' => 'Related One']);

        $res = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk();
        $d = $res->json('data');

        $this->assertSame('Gloves', $d['name']);
        $this->assertSame('Long text', $d['description']);
        $this->assertSame('Japan', $d['country_of_origin']);
        $this->assertFalse($d['is_returnable']);
        $this->assertSame(5, $d['max_order_qty']);
        $this->assertSame(['name' => 'Composites', 'slug' => 'composites'], $d['sub_category']);
        $this->assertEquals(300, $d['price']);                                  // the card shows the cheapest variant
        $this->assertEquals(600, $d['mrp']);
        $this->assertSame(6, $d['reward_points']);
        $this->assertTrue($d['in_stock']);
        $this->assertSame(['features', 'warranty'], array_column($d['content_tabs'], 'key'));   // in the tab order
        $this->assertSame('Features', $d['content_tabs'][0]['title']);
        $this->assertSame('bullets', $d['content_tabs'][0]['format']);

        $this->assertCount(2, $d['variants']);
        $this->assertSame(['id', 'name', 'sku', 'is_default', 'mrp', 'price', 'discount_percent', 'reward_points', 'expiry_date', 'is_cod_available',
            'in_stock', 'qty', 'low_stock', 'price_tiers', 'image_url', 'thumb_url', 'images'], array_keys($d['variants'][0]));
        $this->assertSame('Small', $d['variants'][0]['name']);
        $this->assertEquals(400, $d['variants'][0]['price']);
        $this->assertEquals(20, $d['variants'][0]['discount_percent']);
        $this->assertSame('2030-01-31', $d['variants'][0]['expiry_date']);
        $this->assertTrue($d['variants'][0]['in_stock']);
        $this->assertSame(10, $d['variants'][0]['qty']);
        $this->assertFalse($d['variants'][1]['in_stock']);

        $this->assertSame([], $d['images']);
        $this->assertSame(['related-one'], array_column($d['related'], 'slug'));                   // same category fallback
        $this->assertArrayHasKey('brand', $d['related'][0]);
        $this->assertNotNull($related);
        $this->getJson(self::STORE.'/products/not-a-product')->assertNotFound();
    }

    public function test_hand_picked_related_products_win_over_the_category_fallback(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $main = $this->makeProduct($company, $category, ['name' => 'Main']);
        $this->makeProduct($company, $category, ['name' => 'Same Category']);
        $picked = $this->makeProduct($company, $this->makeCategory('Other'), ['name' => 'Picked']);
        $hidden = $this->makeProduct($company, $category, ['name' => 'Hidden Pick', 'status' => 'draft']);
        DB::table('product_relations')->insert([
            ['product_id' => $main->id, 'related_product_id' => $picked->id, 'type' => 'related', 'sort_order' => 1],
            ['product_id' => $main->id, 'related_product_id' => $hidden->id, 'type' => 'related', 'sort_order' => 2],
        ]);

        $related = $this->getJson(self::STORE.'/products/main')->json('data.related');
        $this->assertSame(['picked'], array_column($related, 'slug'));      // hidden products never leak through
    }

    public function test_the_detail_page_does_not_run_queries_per_variant(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $variants = fn (int $n) => array_map(fn ($i) => ['name' => "V$i", 'sku' => "V$i-".$n, 'mrp' => 10, 'selling_price' => 9, 'stock' => ['standard' => 2]], range(1, $n));
        $few = $this->makeProduct($company, $category, ['name' => 'Few', 'variants' => $variants(1)]);
        $many = $this->makeProduct($company, $category, ['name' => 'Many', 'variants' => $variants(8)], ['best-seller']);
        $count = function (string $slug) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::STORE.'/products/'.$slug)->assertOk();

            return count(DB::getQueryLog());
        };

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

    // ------------------------------------------------------------------ reviews

    public function test_reviews_list_starts_empty_with_a_zero_summary(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());

        $this->getJson(self::STORE.'/products/'.$product->slug.'/reviews')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.avg', 0)->assertJsonPath('summary.count', 0)
            ->assertJsonPath('summary.distribution', ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0]);
        $this->getJson(self::STORE.'/products/nope/reviews')->assertNotFound();
    }

    public function test_posting_a_review_needs_a_customer_login(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::STORE.'/products/'.$product->slug.'/reviews';

        $this->postJson($url, ['rating' => 5])->assertUnauthorized();

        $this->actingAsAdmin();                                  // an admin token is not a customer
        $this->postJson($url, ['rating' => 5])->assertUnauthorized();
        $this->assertSame(0, ProductReview::count());
    }

    public function test_posting_a_review_validates_the_input(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::STORE.'/products/'.$product->slug.'/reviews';
        Sanctum::actingAs($this->customer());

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson($url, ['rating' => 0])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson($url, ['rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson($url, ['rating' => 3.5])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->postJson($url, ['rating' => 5, 'title' => str_repeat('a', 161)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($url, ['rating' => 5, 'body' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, ProductReview::count());
    }

    public function test_one_review_per_customer_and_the_rating_numbers_are_recomputed(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::STORE.'/products/'.$product->slug.'/reviews';

        $asha = $this->customer('asha@example.test', 'Asha Verma');
        $ravi = $this->customer('ravi@example.test', 'Ravi');
        $meena = $this->customer('meena@example.test', 'Meena K Sharma');

        // first review: 201
        Sanctum::actingAs($asha);
        $this->postJson($url, ['rating' => 5, 'title' => 'Great', 'body' => 'Works well'])->assertCreated()
            ->assertJsonPath('data.rating', 5)->assertJsonPath('data.title', 'Great')->assertJsonPath('data.author', 'Asha V.')->assertJsonPath('data.is_verified_purchase', false)
            ->assertJsonPath('summary.count', 1)->assertJsonPath('summary.avg', 5);

        // posting again updates the same review: 200, still one row
        $this->postJson($url, ['rating' => 3, 'body' => 'Changed my mind'])->assertOk()
            ->assertJsonPath('data.rating', 3)->assertJsonPath('data.title', null)->assertJsonPath('summary.count', 1)->assertJsonPath('summary.avg', 3);
        $this->assertSame(1, ProductReview::count());

        Sanctum::actingAs($ravi);
        $this->postJson($url, ['rating' => 4])->assertCreated()->assertJsonPath('data.author', 'Ravi');
        Sanctum::actingAs($meena);
        $this->postJson($url, ['rating' => 4])->assertCreated()->assertJsonPath('data.author', 'Meena S.');

        // numbers: ratings 3, 4, 4 -> avg 3.67, count 3, distribution 0/0/1/2/0
        $stats = ProductRatingStat::find($product->id);
        $this->assertSame(3, $stats->count);
        $this->assertEquals(3.67, $stats->avg);
        $this->assertSame([0, 0, 1, 2, 0], [$stats->c1, $stats->c2, $stats->c3, $stats->c4, $stats->c5]);
        $fresh = $product->fresh();
        $this->assertSame(3, $fresh->rating_count);
        $this->assertEquals(3.7, $fresh->rating_avg);

        // the public list shows them newest first, with the summary; the card and detail carry the same numbers
        $list = $this->getJson($url)->assertOk();
        $list->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.avg', 3.67)->assertJsonPath('summary.count', 3)
            ->assertJsonPath('summary.distribution', ['1' => 0, '2' => 0, '3' => 1, '4' => 2, '5' => 0])
            ->assertJsonPath('data.0.author', 'Meena S.');
        $this->assertSame(['id', 'rating', 'title', 'body', 'author', 'is_verified_purchase', 'created_at'], array_keys($list->json('data.0')));
        $this->getJson(self::STORE.'/products/'.$product->slug)->assertJsonPath('data.rating_count', 3)->assertJsonPath('data.rating_avg', 3.7);
        $this->assertCount(1, $this->slugs('rating=3'));
        $this->assertSame([], $this->slugs('rating=4'));
    }

    public function test_changing_a_review_moves_the_distribution_and_a_hidden_review_is_not_counted(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::STORE.'/products/'.$product->slug.'/reviews';
        Sanctum::actingAs($asha = $this->customer('a@example.test'));
        $this->postJson($url, ['rating' => 1])->assertCreated();
        Sanctum::actingAs($this->customer('b@example.test'));
        $this->postJson($url, ['rating' => 5])->assertCreated()->assertJsonPath('summary.avg', 3);

        Sanctum::actingAs($asha);
        $this->postJson($url, ['rating' => 5])->assertOk()
            ->assertJsonPath('summary.distribution', ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 2])->assertJsonPath('summary.avg', 5);

        ProductReview::where('user_id', $asha->id)->update(['status' => 'hidden']);
        app(\App\Domain\Review\Actions\RecomputeProductRating::class)->handle($product->id);
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('summary.count', 1);
        $this->assertSame(1, $product->fresh()->rating_count);
    }

    public function test_a_deleted_review_can_be_posted_again(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::STORE.'/products/'.$product->slug.'/reviews';
        Sanctum::actingAs($this->customer());
        $this->postJson($url, ['rating' => 2])->assertCreated();
        ProductReview::first()->delete();

        $this->postJson($url, ['rating' => 4])->assertCreated()->assertJsonPath('summary.count', 1)->assertJsonPath('summary.avg', 4);
        $this->assertSame(1, ProductReview::withTrashed()->count());
    }

    public function test_reviews_of_a_hidden_product_are_not_available(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['status' => 'draft']);
        Sanctum::actingAs($this->customer());

        $this->getJson(self::STORE.'/products/'.$product->slug.'/reviews')->assertNotFound();
        $this->postJson(self::STORE.'/products/'.$product->slug.'/reviews', ['rating' => 5])->assertNotFound();
    }

    // ------------------------------------------------------------------ dentalkart-style product page

    public function test_the_product_page_has_option_pictures_quantity_prices_delivery_and_return_days(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $product = $this->makeProduct($company, $category, [
            'name' => 'Maarc Pure Eugenol', 'return_days' => 10, 'meta_title' => 'Eugenol', 'meta_description' => 'Pure eugenol',
            'variants' => [
                ['name' => 'Maarc Pure Eugenol (15ml) (3002/015)', 'sku' => '3002-015', 'mrp' => 190, 'selling_price' => 160, 'reward_points' => 3,
                    'price_tiers' => [['min_qty' => 2, 'price' => 155], ['min_qty' => 5, 'price' => 152]], 'stock' => ['standard' => 3]],
                ['name' => 'Maarc Pure Eugenol (30ml) (3002/030)', 'sku' => '3002-030', 'mrp' => 330, 'selling_price' => 210, 'stock' => ['standard' => 40]],
            ],
        ]);
        [$small, $large] = $product->variants()->get()->all();
        $small->update(['is_cod_available' => false]);
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('main.jpg', 700, 700))->toMediaCollection('images');
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v.jpg', 700, 700))->withCustomProperties(['variant_id' => $small->id])->toMediaCollection('images');
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v2.jpg', 700, 700))->withCustomProperties(['variant_id' => $small->id])->toMediaCollection('images');

        $d = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->json('data');

        // different names per option
        $this->assertSame(['Maarc Pure Eugenol (15ml) (3002/015)', 'Maarc Pure Eugenol (30ml) (3002/030)'], array_column($d['variants'], 'name'));
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
        // low stock, cod override, delivery, return period, meta
        $this->assertTrue($d['variants'][0]['low_stock']);                // 3 left, threshold 5
        $this->assertFalse($d['variants'][1]['low_stock']);
        $this->assertFalse($d['variants'][0]['is_cod_available']);        // the option overrides the product
        $this->assertTrue($d['variants'][1]['is_cod_available']);
        $this->assertSame(10, $d['return_days']);
        $this->assertSame(['channel' => 'standard', 'days' => 5, 'arrives_by' => now()->addDays(5)->toDateString()], $d['delivery']);
        $this->assertSame('Eugenol', $d['meta_title']);
        $this->assertEquals(3, $d['variants'][0]['reward_points']);

        // Instadent delivers faster
        Sanctum::actingAs($this->customer());
        $this->assertSame(3, $this->getJson(self::STORE.'/products/'.$product->slug.'?channel=instadent')->json('data.delivery.days'));
    }

    public function test_a_product_is_a_parent_of_its_options_and_starts_at_the_cheapest_one(): void
    {
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $gun = $this->makeProduct($company, $category, ['name' => 'Glock', 'variants' => [
            ['name' => 'Glock 17', 'sku' => 'G17', 'mrp' => 900, 'selling_price' => 800, 'stock' => ['standard' => 2]],
            ['name' => 'Glock 19', 'sku' => 'G19', 'mrp' => 700, 'selling_price' => 600, 'stock' => ['standard' => 0]],
            ['name' => 'Glock 26', 'sku' => 'G26', 'mrp' => 800, 'selling_price' => 650, 'stock' => ['standard' => 5]],
        ]]);
        $single = $this->makeProduct($company, $category, ['name' => 'Single Gun']);

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
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $offers = $this->makeProduct($company, $category, ['name' => 'Scan Body', 'variants' => [
            ['name' => 'Scan Body NC Compatible', 'sku' => 'SB', 'mrp' => 1490, 'selling_price' => 840,
                'price_tiers' => [['min_qty' => 2, 'price' => 820], ['min_qty' => 5, 'price' => 800], ['min_qty' => 10, 'price' => 760]], 'stock' => ['standard' => 3]],
        ]]);
        $this->makeProduct($company, $category, ['name' => 'Plain Gun']);
        $this->makeProduct($company, $category, ['name' => 'Glock', 'variants' => [
            ['name' => 'Glock 17', 'sku' => 'G17', 'mrp' => 900, 'selling_price' => 800, 'price_tiers' => [['min_qty' => 2, 'price' => 790]], 'stock' => ['standard' => 2]],
            ['name' => 'Glock 19', 'sku' => 'G19', 'mrp' => 700, 'selling_price' => 600, 'stock' => ['standard' => 1]],
        ]]);

        $cards = collect($this->getJson(self::STORE.'/products')->json('data'))->keyBy('slug');

        // one option with bulk prices: its name, a direct price and "3 Offers"
        $c = $cards['scan-body'];
        $this->assertTrue($c['has_single_option']);
        $this->assertFalse($c['show_starting_at']);
        $this->assertSame(3, $c['offers_count']);
        $this->assertSame('3 Offers', $c['badge_label']);
        $this->assertSame('Scan Body NC Compatible', $c['name']);
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
        $this->assertSame('Scan Body NC Compatible', $d['name']);
        $this->assertSame('Scan Body', $d['product_name']);
        $this->assertSame('SB', $d['sku']);
        $this->assertSame('multiple_options', $this->getJson(self::STORE.'/products/glock')->json('data.display_mode'));
        $this->assertSame('Glock', $this->getJson(self::STORE.'/products/glock')->json('data.name'));
    }

    public function test_every_option_has_its_own_stock_in_every_channel(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['name' => 'Shirt', 'variants' => [
            ['name' => 'Shirt Red', 'sku' => 'S-R', 'mrp' => 10, 'selling_price' => 9, 'stock' => ['standard' => 3, 'instadent' => 0]],
            ['name' => 'Shirt Blue', 'sku' => 'S-B', 'mrp' => 10, 'selling_price' => 9, 'stock' => ['standard' => 10, 'instadent' => 7]],
        ]]);
        Sanctum::actingAs($this->customer());

        $standard = $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.variants');
        $this->assertSame([3, 10], array_column($standard, 'qty'));
        $this->assertSame([true, false], array_column($standard, 'low_stock'));   // red: 3 left
        $instadent = $this->getJson(self::STORE.'/products/'.$product->slug.'?channel=instadent')->json('data.variants');
        $this->assertSame([0, 7], array_column($instadent, 'qty'));
        $this->assertSame([false, true], array_column($instadent, 'in_stock'));        // red is sold out on Instadent only
    }

    public function test_an_inactive_option_is_not_sold_and_a_product_without_active_options_is_hidden(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['name' => 'Pen', 'variants' => [
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

    public function test_a_product_that_is_not_returnable_has_no_return_days(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['is_returnable' => false]);

        $this->getJson(self::STORE.'/products/'.$product->slug)->assertJsonPath('data.is_returnable', false)->assertJsonPath('data.return_days', null);
    }

    public function test_when_only_the_options_have_pictures_the_gallery_and_cards_use_them(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $product->addMedia(\Illuminate\Http\UploadedFile::fake()->image('v.jpg', 700, 700))
            ->withCustomProperties(['variant_id' => $product->variants()->value('id')])->toMediaCollection('images');

        $this->assertCount(1, $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.images'));
        $this->assertStringContainsString('/conversions/', $this->getJson(self::STORE.'/products')->json('data.0.image_url'));
    }

    public function test_the_currency_is_pkr(): void
    {
        $this->getJson(self::STORE.'/settings/delivery')->assertOk()->assertJsonPath('currency', 'PKR')->assertJsonPath('currency_symbol', 'Rs');
    }

    public function test_the_review_list_is_paginated(): void
    {
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        foreach (range(1, 3) as $i) {
            Sanctum::actingAs($this->customer("u$i@example.test"));
            $this->postJson(self::STORE.'/products/'.$product->slug.'/reviews', ['rating' => 5])->assertCreated();
        }

        $this->getJson(self::STORE.'/products/'.$product->slug.'/reviews?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('summary.count', 3);
    }
}

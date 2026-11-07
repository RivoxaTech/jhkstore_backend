<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_base_seeder_creates_only_the_two_admins_and_the_default_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Admin::count());
        $this->assertSame(count(Settings::DEFINITIONS), DB::table('settings')->count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame(0, DB::table('customers')->count());
        // nothing of the Phase A features is pre-filled: no zones, discounts, coupons or carts, and the store starts open with cash on delivery
        foreach (['delivery_zones', 'discounts', 'discount_targets', 'coupons', 'coupon_targets', 'coupon_usages', 'carts', 'cart_items', 'orders', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $settings = app(Settings::class);
        $this->assertTrue($settings->get('store_open'));
        $this->assertTrue($settings->get('pay_cod_enabled'));
        $this->assertFalse($settings->get('pay_bank_enabled'));
        $this->assertSame([], $settings->get('pay_bank_accounts'));
        $this->assertSame('', $settings->get('agency_name'));
    }

    public function test_the_demo_seeder_makes_a_toy_catalogue_for_the_storefront_and_can_run_again(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $products = Product::count();
        $media = DB::table('media')->count();
        $this->seed(DemoSeeder::class);   // again: nothing is duplicated
        $this->assertSame($products, Product::count());
        $this->assertSame($media, DB::table('media')->count());

        $this->assertSame(40, Product::count());
        $this->assertSame(6, DB::table('categories')->count());
        $this->assertSame(7, DB::table('tags')->where('is_system', true)->count());
        // some categories have sub-categories, one has none
        $withSubs = DB::table('sub_categories')->distinct()->count('category_id');
        $this->assertGreaterThanOrEqual(4, $withSubs);
        $this->assertLessThan(6, $withSubs);

        // options with their own price and stock, and bulk prices that are real offers
        $this->assertGreaterThan(40, DB::table('product_variants')->count());
        $tiers = DB::table('variant_price_tiers')->join('product_variants', 'product_variants.id', '=', 'variant_price_tiers.variant_id')
            ->get(['variant_price_tiers.min_qty', 'variant_price_tiers.price', 'product_variants.selling_price']);
        $this->assertGreaterThan(0, $tiers->count());
        foreach ($tiers as $t) {
            $this->assertGreaterThanOrEqual(2, $t->min_qty);
            $this->assertLessThan($t->selling_price, $t->price);
        }

        // the tags the storefront builds sections from, and the featured switch
        foreach (['best-seller', 'new-arrival', 'featured'] as $tag) {
            $this->assertGreaterThan(0, DB::table('product_tag')->join('tags', 'tags.id', '=', 'product_tag.tag_id')->where('tags.slug', $tag)->count(), $tag);
        }
        $this->assertGreaterThan(0, Product::where('is_featured', true)->count());

        // every product has a local picture (stored like an upload, no remote URL) and every picture file exists
        $this->assertSame(0, Product::query()->whereDoesntHave('media')->count());
        $this->assertSame(0, DB::table('product_images')->count());
        foreach (\Spatie\MediaLibrary\MediaCollections\Models\Media::query()->get() as $m) {
            Storage::disk('public')->assertExists($m->getPathRelativeToRoot());
        }

        // banners, a discount, a coupon, two delivery zones, bank transfer on
        $this->assertSame(['home_hero', 'home_hero', 'home_secondary'], DB::table('banners')->orderBy('id')->pluck('placement')->all());
        $this->assertSame(1, DB::table('discounts')->count());
        $this->assertSame(['WELCOME10'], DB::table('coupons')->pluck('code')->all());
        $this->assertSame(2, DB::table('delivery_zones')->count());
        $this->assertTrue(app(Settings::class)->get('pay_bank_enabled'));
        $this->assertCount(1, app(Settings::class)->publicAccounts());

        // visible on the storefront: the products, the sub-categories, the banners; one product is shown as sold out
        $this->getJson('/api/v1/store/products?per_page=100')->assertOk()->assertJsonCount(40, 'data');
        $this->getJson('/api/v1/store/categories')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/store/banners?placement=home_hero')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(1, collect($this->getJson('/api/v1/store/products?per_page=100')->json('data'))->where('is_sold_out', true)->count());
        $this->assertCount(40, $this->getJson('/api/v1/store/sitemap')->json('products'));
        $this->assertSame(0, DB::table('orders')->count());

        // neutral sample words only, and the real names appear nowhere
        $this->assertSame([], Product::pluck('name')->filter(fn ($n) => ! preg_match('/^[A-Za-z0-9 ,.&\'":()%+-]+$/', $n))->all());
    }
}

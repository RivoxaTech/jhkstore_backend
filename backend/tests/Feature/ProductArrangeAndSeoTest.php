<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The owner arranges the products by drag and drop; the SEO of a product is made from the product itself; the panel can follow orders in real time. */
class ProductArrangeAndSeoTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    /** @return list<int> the product ids a list returns, in order */
    private function ids(string $url): array
    {
        return array_map('intval', collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all());
    }

    /** @return list<Product> */
    private function three(): array
    {
        return [$this->shopProduct(), $this->shopProduct(), $this->shopProduct()];
    }

    public function test_the_admin_list_and_the_website_show_the_arranged_order_and_new_products_come_first(): void
    {
        [$a, $b, $c] = $this->three();
        $this->actingAsAdmin('admin');
        $this->assertSame([$c->id, $b->id, $a->id], $this->ids(self::ADMIN.'/products'));   // nobody arranged yet: the newest first

        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$a->id, $c->id, $b->id]])->assertOk();

        $this->assertSame([$a->id, $c->id, $b->id], $this->ids(self::ADMIN.'/products'));
        $this->assertSame([$a->id, $c->id, $b->id], $this->ids(self::STORE.'/products'));   // the default order of the shop ("Recommended")
        $this->assertSame([1, 2, 3], Product::query()->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());

        $d = $this->shopProduct();   // a new product: first
        $this->assertSame([$d->id, $a->id, $c->id, $b->id], $this->ids(self::STORE.'/products'));
    }

    public function test_a_page_of_the_list_can_be_arranged_without_moving_the_other_pages(): void
    {
        [$a, $b, $c] = $this->three();
        $d = $this->shopProduct();
        $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$a->id, $b->id, $c->id, $d->id]])->assertOk();
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$c->id, $b->id]])->assertOk();   // the two swap places only

        $this->assertSame([$a->id, $c->id, $b->id, $d->id], $this->ids(self::ADMIN.'/products'));
    }

    public function test_arranging_needs_real_products_at_least_two_and_a_login(): void
    {
        [$a, $b] = $this->three();
        $this->actingAsAdmin('admin');
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$a->id, 99999]])->assertStatus(422);
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$a->id]])->assertStatus(422);   // nothing to arrange
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->putJson(self::ADMIN.'/products/reorder', ['ids' => [$a->id, $b->id]])->assertUnauthorized();
    }

    public function test_the_page_title_and_description_are_made_from_the_product_when_the_seo_fields_are_empty(): void
    {
        $product = $this->shopProduct([], ['name' => 'Wooden Train', 'short_description' => 'A chunky train set for small hands', 'description' => '<p>Painted with child-safe colours. Fits any track.</p>', 'meta_title' => null, 'meta_description' => null]);

        $seo = $this->getJson(self::STORE.'/products/'.$product->slug)->assertOk()->json('data.seo');

        $this->assertStringContainsString('A chunky train set for small hands', $seo['description']);
        $this->assertStringContainsString('Painted with child-safe colours', $seo['description']);   // the next sentences of the description
        $this->assertStringNotContainsString('<p>', $seo['description']);
        $this->assertLessThanOrEqual(155, mb_strlen($seo['description']));
        $this->assertNotSame('', $seo['title']);
    }

    public function test_the_owner_s_own_seo_text_wins_and_the_admin_sees_what_would_be_made(): void
    {
        $product = $this->shopProduct([], ['name' => 'Wooden Train', 'short_description' => 'A chunky train set', 'meta_title' => 'My own title', 'meta_description' => 'My own description']);

        $seo = $this->getJson(self::STORE.'/products/'.$product->slug)->json('data.seo');
        $this->assertSame('My own title', $seo['title']);
        $this->assertSame('My own description', $seo['description']);

        $this->actingAsAdmin('admin');
        $admin = $this->getJson(self::ADMIN.'/products/'.$product->id)->assertOk();
        $this->assertSame('My own title', $admin->json('data.meta_title'));
        $this->assertNotSame('My own title', $admin->json('data.seo_auto.title'));
        $this->assertSame('A chunky train set.', $admin->json('data.seo_auto.description'));
    }

    public function test_the_order_badge_call_carries_a_version_that_moves_when_an_order_arrives(): void
    {
        $this->actingAsAdmin('admin');
        $before = $this->getJson(self::ADMIN.'/orders/pending-count')->assertOk()->json('version');
        $this->assertNotEmpty($before);
        $this->assertSame($before, $this->getJson(self::ADMIN.'/orders/pending-count')->json('version'));   // nothing happened: the same version

        $variant = $this->variantOf($this->shopProduct());
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        $this->actingAsAdmin('admin');

        $this->assertNotSame($before, $this->getJson(self::ADMIN.'/orders/pending-count')->json('version'));   // the panel reloads its list
    }
}

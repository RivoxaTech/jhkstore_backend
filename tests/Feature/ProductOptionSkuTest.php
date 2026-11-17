<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** An option that comes without a SKU gets one made from the product's (SKU-2, SKU-3...): free, and never the same as another option. */
class ProductOptionSkuTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function create(array $variants, string $sku = 'TOY-A'): \Illuminate\Testing\TestResponse
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $category = Category::query()->first() ?? $this->makeCategory();

        return $this->postJson('/api/v1/admin/products', [
            'category_id' => $category->id, 'name' => 'Toy A', 'sku' => $sku, 'status' => 'active',
            'variants' => $variants,
        ]);
    }

    private function row(string $name, array $extra = []): array
    {
        return ['name' => $name, 'mrp' => 20, 'selling_price' => 15, 'stock' => 5, ...$extra];
    }

    public function test_options_without_a_sku_get_numbered_ones(): void
    {
        $res = $this->create([$this->row('Red', ['sku' => 'TOY-A', 'is_default' => true]), $this->row('Blue'), $this->row('Green')])->assertCreated();

        $this->assertSame(['TOY-A', 'TOY-A-2', 'TOY-A-3'], array_column($res->json('data.variants'), 'sku'));
    }

    public function test_the_numbers_skip_skus_that_are_taken_in_the_request_or_in_the_shop(): void
    {
        $this->create([$this->row('Only', ['sku' => 'TOY-B-2'])], 'TOY-OTHER')->assertCreated();   // an option elsewhere already uses TOY-B-2

        $res = $this->create([$this->row('Red', ['sku' => 'TOY-B-3', 'is_default' => true]), $this->row('Blue'), $this->row('Green')], 'TOY-B')->assertCreated();

        $skus = array_column($res->json('data.variants'), 'sku');
        $this->assertSame(['TOY-B-3', 'TOY-B-4', 'TOY-B-5'], $skus);
        $this->assertSame(count($skus), count(array_unique($skus)));
        $this->assertSame(count($skus), DB::table('product_variants')->whereIn('sku', $skus)->count());
    }

    public function test_a_typed_sku_is_kept_and_a_duplicate_is_still_refused(): void
    {
        $res = $this->create([$this->row('Red', ['sku' => 'MY-RED', 'is_default' => true]), $this->row('Blue', ['sku' => 'MY-BLUE'])])->assertCreated();
        $this->assertSame(['MY-RED', 'MY-BLUE'], array_column($res->json('data.variants'), 'sku'));

        $this->postJson('/api/v1/admin/products', [
            'category_id' => Category::query()->first()->id, 'name' => 'Other', 'sku' => 'OTHER', 'status' => 'active',
            'variants' => [$this->row('A', ['sku' => 'MY-RED', 'is_default' => true]), $this->row('B')],
        ])->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
    }
}

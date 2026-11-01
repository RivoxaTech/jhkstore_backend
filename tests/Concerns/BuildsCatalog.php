<?php

namespace Tests\Concerns;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use Database\Seeders\TagSeeder;

/** Fixtures for the product tests: categories and fully saved products. */
trait BuildsCatalog
{
    protected function seedCatalog(): void
    {
        $this->seedBasics();
        $this->seed(TagSeeder::class);
    }

    /** An admin that is NOT logged in (store tests must act as guest or customer, never as admin). */
    protected function plainAdmin(): Admin
    {
        return Admin::query()->first() ?? Admin::create(['username' => 'seeder', 'name' => 'Seeder', 'password' => 'secret-pass', 'is_active' => true]);
    }

    protected function makeCategory(string $name = 'Restorative', bool $active = true): Category
    {
        $category = new Category(['name' => $name, 'is_active' => $active]);
        $category->slug = Category::uniqueSlug($name);
        $category->save();

        return $category;
    }

    protected function makeSub(Category $category, string $name = 'Composites', bool $active = true): SubCategory
    {
        $sub = new SubCategory(['name' => $name, 'is_active' => $active]);
        $sub->category_id = $category->id;
        $sub->slug = SubCategory::uniqueSlug($name);
        $sub->save();

        return $sub;
    }

    /** Request/action payload of a simple product (one hidden default variant). Override any key. */
    protected function simplePayload(Category $category, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'category_id' => $category->id,
            'name' => "Test Product $n",
            'sku' => "SKU-$n",
            'mrp' => 1000,
            'selling_price' => 800,
            'reward_points' => 10,
            'stock' => 20,
            'status' => 'active',
        ], $overrides);
    }

    /**
     * A saved product through the real action. Defaults: simple product, MRP 1000 / price 800, 20 in stock, active.
     * Pass `variants` to get a product with options, or the flat price keys to change the single variant.
     */
    protected function makeProduct(Category $category, array $overrides = [], array $tagSlugs = []): Product
    {
        $payload = $this->simplePayload($category, $overrides);
        if (isset($payload['variants'])) {
            unset($payload['mrp'], $payload['selling_price'], $payload['reward_points'], $payload['stock']);
        } else {
            $payload['variants'] = [[
                'name' => $payload['name'], 'sku' => $payload['sku'], 'is_default' => true, 'mrp' => $payload['mrp'], 'selling_price' => $payload['selling_price'],
                'reward_points' => $payload['reward_points'], 'stock' => $payload['stock'],
            ]];
            unset($payload['mrp'], $payload['selling_price'], $payload['reward_points'], $payload['stock']);
        }
        if ($tagSlugs) {
            $payload['tag_ids'] = Tag::whereIn('slug', $tagSlugs)->pluck('id')->all();
        }

        return app(SaveProduct::class)->create($payload, $this->plainAdmin());
    }
}

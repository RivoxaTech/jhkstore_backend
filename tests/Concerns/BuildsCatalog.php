<?php

namespace Tests\Concerns;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Company\Actions\CreateCompany;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use Database\Seeders\TagSeeder;

/** Fixtures for the product tests: companies, brands, categories and fully saved products. */
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

    /** Admin-created companies are approved at once (with one active brand "<name> Brand"); public ones stay pending. */
    protected function makeCompany(string $name = 'Acme Co', bool $approved = true): Company
    {
        static $n = 0;
        $n++;

        return app(CreateCompany::class)->execute([
            'name' => $name,
            'registration_type_id' => CompanyRegistrationType::value('id'),
            'vendor_type_id' => VendorType::value('id'),
            'origin_country_id' => Country::value('id'),
            'email' => "c$n@b.test", 'contact_number' => '9999999999', 'vat_gst_number' => "GST-PT-$n",
            'contact_name' => 'X', 'contact_mobile' => '9999999999', 'contact_email' => 'x@b.test',
            'address' => 'Road', 'city' => 'Delhi', 'zip_code' => '110001', 'promotion_support' => str_repeat('a', 200),
        ], [['name' => $name.' Brand']], [], $approved ? CompanySource::Admin : CompanySource::PublicForm, $approved ? $this->plainAdmin() : null);
    }

    protected function makeBrand(Company $company, string $name): Brand
    {
        $brand = new Brand(['company_id' => $company->id, 'name' => $name, 'is_active' => true]);
        $brand->slug = Brand::uniqueSlug($name);
        $brand->save();

        return $brand;
    }

    protected function makeCategory(string $name = 'Restorative', bool $active = true): Category
    {
        $category = new Category(['name' => $name, 'is_active' => $active]);
        $category->slug = Category::uniqueSlug($name);
        $category->save();

        return $category;
    }

    protected function makeSub(Category $category, string $name = 'Composites', bool $active = true, ?SubCategory $parent = null): SubCategory
    {
        $sub = new SubCategory(['name' => $name, 'is_active' => $active]);
        $sub->category_id = $category->id;
        $sub->parent_id = $parent?->id;
        $sub->slug = SubCategory::uniqueSlug($name);
        $sub->save();

        return $sub;
    }

    /** Request/action payload of a simple product (one hidden default variant). Override any key. */
    protected function simplePayload(Company $company, Category $category, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'company_id' => $company->id,
            'brand_id' => $company->brands()->value('id'),
            'category_id' => $category->id,
            'name' => "Test Product $n",
            'sku' => "SKU-$n",
            'mrp' => 1000,
            'selling_price' => 800,
            'reward_points' => 10,
            'stock' => ['standard' => 20, 'instadent' => 5],
            'channels' => [['code' => 'standard', 'is_active' => true], ['code' => 'instadent', 'is_active' => true]],
            'status' => 'active',
        ], $overrides);
    }

    /**
     * A saved product through the real action. Defaults: simple product, MRP 1000 / price 800, both channels, 20 + 5 in stock, active.
     * Pass `variants` to get a product with options, or the flat price keys to change the single variant.
     */
    protected function makeProduct(Company $company, Category $category, array $overrides = [], array $tagSlugs = []): Product
    {
        $payload = $this->simplePayload($company, $category, $overrides);
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

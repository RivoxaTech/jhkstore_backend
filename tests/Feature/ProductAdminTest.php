<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Support\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ProductAdminTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedCatalog();
    }

    private function create(array $payload)
    {
        return $this->postJson(self::ADMIN.'/products', $payload);
    }

    // ------------------------------------------------------------------ access

    public function test_product_endpoints_need_an_admin_login(): void
    {
        $this->getJson(self::ADMIN.'/products')->assertUnauthorized();
        $this->postJson(self::ADMIN.'/products', [])->assertUnauthorized();
        $this->getJson(self::ADMIN.'/tags')->assertUnauthorized();
        $this->getJson(self::ADMIN.'/products/options')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ create

    public function test_admin_creates_a_simple_product_with_one_hidden_default_variant(): void
    {
        $admin = $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $sub = $this->makeSub($category);

        $res = $this->create($this->simplePayload($company, $category, [
            'name' => 'Prime Composite Kit', 'sku' => 'PCK-1', 'sub_category_id' => $sub->id,
            'mrp' => 4500, 'selling_price' => 2495, 'reward_points' => 25, 'badge_text' => 'BEST SELLER', 'hsn_code' => '3006', 'gst_rate' => 12,
        ]))->assertCreated();

        $res->assertJsonPath('data.name', 'Prime Composite Kit')
            ->assertJsonPath('data.slug', 'prime-composite-kit')       // slug is automatic
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.variants.0.name', 'Prime Composite Kit')   // the single option is named after the product
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.is_default', true)
            ->assertJsonPath('data.variants.0.sku', 'PCK-1')           // the default variant takes the product SKU
            ->assertJsonPath('data.variants.0.discount_percent', 44.56) // (4500-2495)/4500
            ->assertJsonPath('data.variants.0.reward_points', 25)
            ->assertJsonPath('data.min_price', 2495)
            ->assertJsonPath('data.max_discount_percent', 44.56)
            ->assertJsonPath('data.is_in_stock', true)
            ->assertJsonPath('data.brand.id', $company->brands()->value('id'))
            ->assertJsonPath('data.sub_category.id', $sub->id)
            ->assertJsonPath('data.gst_rate', 12);

        $this->assertNotNull(Product::first()->published_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'actor_id' => $admin->id]);
    }

    public function test_a_product_gets_the_standard_channel_and_draft_status_by_default(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $payload = $this->simplePayload($company, $this->makeCategory());
        unset($payload['channels'], $payload['status'], $payload['stock']);

        $this->create($payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.channels')
            ->assertJsonPath('data.channels.0.code', 'standard')
            ->assertJsonPath('data.is_in_stock', false)
            ->assertJsonPath('data.published_at', null);
    }

    public function test_slugs_are_generated_unique_and_can_be_set(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();

        $first = $this->create($this->simplePayload($company, $category, ['name' => 'Same Name', 'sku' => 'A-1']))->assertCreated();
        $second = $this->create($this->simplePayload($company, $category, ['name' => 'Same Name', 'sku' => 'A-2']))->assertCreated();
        $this->assertSame('same-name', $first->json('data.slug'));
        $this->assertSame('same-name-2', $second->json('data.slug'));

        $this->create($this->simplePayload($company, $category, ['name' => 'Third', 'sku' => 'A-3', 'slug' => 'same-name']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->create($this->simplePayload($company, $category, ['name' => 'Fourth', 'sku' => 'A-4', 'slug' => 'my-own-slug']))
            ->assertCreated()->assertJsonPath('data.slug', 'my-own-slug');
    }

    public function test_product_with_options_tags_channels_stock_per_channel_and_content_tabs(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $tags = Tag::whereIn('slug', ['best-seller', 'new-arrival'])->pluck('id')->all();

        $res = $this->create($this->simplePayload($company, $category, [
            'name' => 'Gloves', 'sku' => 'GLV',
            'variants' => [
                ['name' => 'Small', 'sku' => 'GLV-S', 'mrp' => 500, 'selling_price' => 400, 'reward_points' => 4, 'expiry_date' => '2030-01-31', 'stock' => ['standard' => 10, 'instadent' => 3]],
                ['name' => 'Large', 'sku' => 'GLV-L', 'mrp' => 600, 'selling_price' => 300, 'reward_points' => 6, 'stock' => ['standard' => 0, 'instadent' => 7]],
            ],
            'tag_ids' => $tags,
            'content_tabs' => [
                ['tab_key' => 'features', 'format' => 'bullets', 'content' => "Soft\nStrong"],
                ['tab_key' => 'warranty', 'content' => '1 year'],
                ['tab_key' => 'packaging', 'content' => ''], // empty tabs are dropped
            ],
        ]))->assertCreated();

        $res->assertJsonPath('data.min_price', 300)
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.stock.standard', 10)
            ->assertJsonPath('data.variants.0.stock.instadent', 3)
            ->assertJsonPath('data.variants.1.stock.standard', 0)
            ->assertJsonPath('data.variants.1.stock.instadent', 7)
            ->assertJsonPath('data.variants.1.discount_percent', 50)
            ->assertJsonPath('data.min_price', 300)
            ->assertJsonPath('data.max_discount_percent', 50)
            ->assertJsonPath('data.is_in_stock', true)
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(2, 'data.content_tabs')
            ->assertJsonPath('data.content_tabs.0.tab_key', 'features')
            ->assertJsonPath('data.content_tabs.0.label', 'Features');

        // exactly one default variant, and the stock is separate per channel
        $product = Product::first();
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
        $this->assertSame(4, DB::table('variant_channel_stock')->count());
        // every quantity written is logged
        $this->assertSame(3, InventoryMovement::where('type', 'initial')->count()); // the 0 quantity needs no movement
    }

    public function test_a_product_can_be_instadent_only(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();

        $this->create($this->simplePayload($company, $this->makeCategory(), ['channels' => [['code' => 'instadent', 'is_active' => true]]]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.channels')
            ->assertJsonPath('data.channels.0.code', 'instadent');
    }

    // ------------------------------------------------------------------ validation

    public function test_company_brand_category_and_sub_category_rules(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('Acme');
        $other = $this->makeCompany('Other');
        $pending = $this->makeCompany('Pending Co', approved: false);
        $category = $this->makeCategory('Cat A');
        $otherCategory = $this->makeCategory('Cat B');
        $subOfOther = $this->makeSub($otherCategory, 'Sub B');
        $inactiveBrand = $this->makeBrand($company, 'Dormant');
        $inactiveBrand->update(['is_active' => false]);

        // the brand must belong to the selected company
        $this->create($this->simplePayload($company, $category, ['brand_id' => $other->brands()->value('id')]))
            ->assertUnprocessable()->assertJsonValidationErrors('brand_id');
        // only an approved company
        $this->create($this->simplePayload($pending, $category))
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        // the brand must be active
        $this->create($this->simplePayload($company, $category, ['brand_id' => $inactiveBrand->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('brand_id');
        // the sub-category must belong to the category
        $this->create($this->simplePayload($company, $category, ['sub_category_id' => $subOfOther->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('sub_category_id');
        // an inactive category cannot be chosen
        $off = $this->makeCategory('Off', active: false);
        $this->create($this->simplePayload($company, $off))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        // unknown ids
        $this->create($this->simplePayload($company, $category, ['category_id' => 9999]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        // required fields
        $this->create([])->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'brand_id', 'category_id', 'name', 'sku', 'variants']);

        $this->assertSame(0, Product::count());
    }

    public function test_price_cannot_be_higher_than_mrp(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();

        // simple product
        $this->create($this->simplePayload($company, $category, ['mrp' => 100, 'selling_price' => 150]))
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.selling_price');
        // product with options
        $this->create($this->simplePayload($company, $category, ['variants' => [
            ['name' => 'A', 'sku' => 'A', 'mrp' => 100, 'selling_price' => 90],
            ['name' => 'B', 'sku' => 'B', 'mrp' => 100, 'selling_price' => 101],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('variants.1.selling_price');
        // a price equal to the MRP is fine (0% off)
        $this->create($this->simplePayload($company, $category, ['mrp' => 100, 'selling_price' => 100]))
            ->assertCreated()->assertJsonPath('data.variants.0.discount_percent', 0);
        // mrp is required and positive
        $this->create($this->simplePayload($company, $category, ['mrp' => 0, 'selling_price' => 0, 'sku' => 'Z']))
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.mrp');
    }

    public function test_sku_must_be_unique_for_products_and_variants(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $this->create($this->simplePayload($company, $category, ['sku' => 'DUP-1']))->assertCreated();

        $this->create($this->simplePayload($company, $category, ['sku' => 'DUP-1']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        // a variant SKU that another variant already owns
        $this->create($this->simplePayload($company, $category, ['sku' => 'NEW-1', 'variants' => [
            ['name' => 'A', 'sku' => 'DUP-1', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'B', 'sku' => 'NEW-1B', 'mrp' => 10, 'selling_price' => 9],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.sku');
        // two variants with the same SKU in one request
        $this->create($this->simplePayload($company, $category, ['sku' => 'NEW-2', 'variants' => [
            ['name' => 'A', 'sku' => 'SAME', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'B', 'sku' => 'SAME', 'mrp' => 10, 'selling_price' => 9],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('variants.1.sku');
        // options need a name and a SKU
        $this->create($this->simplePayload($company, $category, ['sku' => 'NEW-3', 'variants' => [
            ['mrp' => 10, 'selling_price' => 9], ['name' => 'B', 'sku' => 'B3', 'mrp' => 10, 'selling_price' => 9],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['variants.0.name', 'variants.0.sku']);
    }

    public function test_other_field_rules(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();

        $this->create($this->simplePayload($company, $category, ['status' => 'live']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->create($this->simplePayload($company, $category, ['min_order_qty' => 5, 'max_order_qty' => 2]))->assertUnprocessable()->assertJsonValidationErrors('max_order_qty');
        $this->create($this->simplePayload($company, $category, ['channels' => [['code' => 'amazon']]]))->assertUnprocessable()->assertJsonValidationErrors('channels.0.code');
        $this->create($this->simplePayload($company, $category, ['tag_ids' => [9999]]))->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');
        $this->create($this->simplePayload($company, $category, ['stock' => ['standard' => -1]]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.stock.standard');
        $this->create($this->simplePayload($company, $category, ['content_tabs' => [['tab_key' => 'nonsense', 'content' => 'x']]]))->assertUnprocessable()->assertJsonValidationErrors('content_tabs.0.tab_key');
    }

    // ------------------------------------------------------------------ update

    public function test_update_recalculates_discount_and_denormalized_columns_and_logs_stock_changes(): void
    {
        $admin = $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $product = $this->makeProduct($company, $category, ['mrp' => 1000, 'selling_price' => 800, 'stock' => ['standard' => 10, 'instadent' => 0]]);
        $this->assertSame('20.00', $product->fresh()->max_discount_percent);

        $this->putJson(self::ADMIN."/products/{$product->id}", [
            'mrp' => 1000, 'selling_price' => 600, 'reward_points' => 30, 'stock' => ['standard' => 4, 'instadent' => 9],
        ])->assertOk()
            ->assertJsonPath('data.variants.0.discount_percent', 40)
            ->assertJsonPath('data.min_price', 600)
            ->assertJsonPath('data.max_discount_percent', 40)
            ->assertJsonPath('data.variants.0.stock.standard', 4)
            ->assertJsonPath('data.variants.0.stock.instadent', 9)
            ->assertJsonCount(1, 'data.variants'); // the same variant was updated, not duplicated

        $variantId = $product->variants()->value('id');
        $this->assertSame(
            [-6, 9],
            InventoryMovement::where('variant_id', $variantId)->where('type', 'adjustment')->orderBy('channel_id')->pluck('qty_change')->all(),
        );
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variantId, 'type' => 'adjustment', 'balance_after' => 4, 'admin_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated']);

        // selling out everything flips is_in_stock
        $this->putJson(self::ADMIN."/products/{$product->id}", ['mrp' => 1000, 'selling_price' => 600, 'stock' => ['standard' => 0, 'instadent' => 0]])
            ->assertOk()->assertJsonPath('data.is_in_stock', false);
    }

    public function test_saving_an_old_form_does_not_give_sold_units_back(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['stock' => ['standard' => 100, 'instadent' => 20]]);
        $variantId = $product->variants()->value('id');
        $stock = fn (string $code) => (int) DB::table('variant_channel_stock')->where('variant_id', $variantId)->where('channel_id', SalesChannel::idsByCode()[$code])->value('stock_qty');

        // 9 units were sold while the admin had the form open (the form still shows 100)
        DB::table('variant_channel_stock')->where('variant_id', $variantId)->where('channel_id', SalesChannel::idsByCode()['standard'])->update(['stock_qty' => 91]);

        $form = fn (array $stock, array $was) => [
            'mrp' => 1000, 'selling_price' => 700, 'stock' => $stock, 'stock_was' => $was,
        ];

        // price edited only, stock box untouched (100 = 100): the live 91 stays
        $this->putJson(self::ADMIN."/products/{$product->id}", $form(['standard' => 100, 'instadent' => 20], ['standard' => 100, 'instadent' => 20]))->assertOk();
        $this->assertSame(91, $stock('standard'));

        // the admin really adds 10 (100 -> 110): applied on top of what is really left
        $this->putJson(self::ADMIN."/products/{$product->id}", $form(['standard' => 110, 'instadent' => 20], ['standard' => 100, 'instadent' => 20]))->assertOk();
        $this->assertSame(101, $stock('standard'));
        $this->assertSame(20, $stock('instadent'));

        // removing more than is left never goes below zero
        $this->putJson(self::ADMIN."/products/{$product->id}", $form(['standard' => 0, 'instadent' => 20], ['standard' => 500, 'instadent' => 20]))->assertOk();
        $this->assertSame(0, $stock('standard'));

        // without stock_was (API clients) the number is absolute, as before
        $this->putJson(self::ADMIN."/products/{$product->id}", ['mrp' => 1000, 'selling_price' => 700, 'stock' => ['standard' => 33, 'instadent' => 20]])->assertOk();
        $this->assertSame(33, $stock('standard'));
    }

    public function test_update_keeps_the_slug_and_changes_only_what_is_sent(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $product = $this->makeProduct($company, $this->makeCategory(), ['name' => 'Old Name', 'sku' => 'KEEP-1']);

        $this->putJson(self::ADMIN."/products/{$product->id}", ['name' => 'New Name', 'is_cod_available' => false, 'badge_text' => 'NEW'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'old-name')
            ->assertJsonPath('data.sku', 'KEEP-1')
            ->assertJsonPath('data.is_cod_available', false)
            ->assertJsonPath('data.variants.0.mrp', 1000)   // variants untouched
            ->assertJsonCount(2, 'data.channels');           // channels untouched
        // sku and slug may keep their own value in a full update
        $this->putJson(self::ADMIN."/products/{$product->id}", ['sku' => 'KEEP-1', 'slug' => 'old-name'])->assertOk();
    }

    public function test_update_can_turn_a_simple_product_into_one_with_options_and_back(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $product = $this->makeProduct($company, $this->makeCategory(), ['sku' => 'FLIP']);
        $defaultId = $product->variants()->value('id');

        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [
            ['id' => $defaultId, 'name' => '10 pcs', 'sku' => 'FLIP', 'is_default' => true, 'mrp' => 100, 'selling_price' => 90, 'stock' => ['standard' => 5]],
            ['name' => '50 pcs', 'sku' => 'FLIP-50', 'mrp' => 450, 'selling_price' => 360, 'stock' => ['standard' => 5]],
        ]])->assertOk()->assertJsonCount(2, 'data.variants');

        // the price fields of a simple product are refused while the product has options
        $this->putJson(self::ADMIN."/products/{$product->id}", ['mrp' => 10, 'selling_price' => 9])
            ->assertUnprocessable()->assertJsonValidationErrors('variants');

        // a variants list with only the first one removes the other
        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [
            ['id' => $defaultId, 'name' => 'Default', 'sku' => 'FLIP', 'mrp' => 100, 'selling_price' => 90],
        ]])->assertOk()->assertJsonCount(1, 'data.variants')->assertJsonPath('data.min_price', 90);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'FLIP-50']);
    }

    public function test_update_keeps_the_brand_company_and_category_consistent(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('Acme');
        $other = $this->makeCompany('Other');
        $category = $this->makeCategory('A');
        $otherCategory = $this->makeCategory('B');
        $sub = $this->makeSub($category, 'A-sub');
        $product = $this->makeProduct($company, $category, ['sub_category_id' => $sub->id]);
        $url = self::ADMIN."/products/{$product->id}";

        // moving to another company needs one of its brands
        $this->putJson($url, ['company_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('brand_id');
        $this->putJson($url, ['company_id' => $other->id, 'brand_id' => $other->brands()->value('id')])->assertOk();
        // moving to another category needs a sub-category of it
        $this->putJson($url, ['category_id' => $otherCategory->id])->assertUnprocessable()->assertJsonValidationErrors('sub_category_id');
        $this->putJson($url, ['category_id' => $otherCategory->id, 'sub_category_id' => null])->assertOk()->assertJsonPath('data.sub_category', null);
    }

    public function test_a_brand_that_was_switched_off_later_does_not_block_other_edits(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $product = $this->makeProduct($company, $this->makeCategory());
        $product->brand->update(['is_active' => false]);

        $this->putJson(self::ADMIN."/products/{$product->id}", ['company_id' => $company->id, 'brand_id' => $product->brand_id, 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    public function test_variants_that_are_in_an_order_are_switched_off_not_deleted(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $product = $this->makeProduct($company, $this->makeCategory(), ['sku' => 'ORD', 'variants' => [
            ['name' => 'A', 'sku' => 'ORD-A', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'B', 'sku' => 'ORD-B', 'mrp' => 10, 'selling_price' => 9],
        ]]);
        [$a, $b] = $product->variants()->get()->all();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('order_items'));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_items')->insert(['id' => 1, 'order_id' => 1, 'product_id' => $product->id, 'variant_id' => $b->id] + $this->orderItemDefaults());
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [
            ['id' => $a->id, 'name' => 'A', 'sku' => 'ORD-A', 'mrp' => 10, 'selling_price' => 9],
        ]])->assertOk()->assertJsonCount(2, 'data.variants');

        $this->assertDatabaseHas('product_variants', ['id' => $b->id, 'is_active' => false]);
    }

    /** Required columns of order_items without a default (read from the schema, so the test follows the migration). */
    private function orderItemDefaults(): array
    {
        $defaults = [];
        foreach (DB::select('SHOW COLUMNS FROM order_items') as $col) {
            if ($col->Null === 'NO' && $col->Default === null && $col->Extra !== 'auto_increment' && ! in_array($col->Field, ['order_id', 'product_id', 'variant_id'], true)) {
                $defaults[$col->Field] = str_contains($col->Type, 'int') || str_contains($col->Type, 'decimal') ? 1 : 'x';
            }
        }

        return $defaults;
    }

    // ------------------------------------------------------------------ show, delete, quick endpoints

    public function test_show_and_soft_delete(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());

        $this->getJson(self::ADMIN."/products/{$product->id}")->assertOk()->assertJsonPath('data.id', $product->id);
        $this->deleteJson(self::ADMIN."/products/{$product->id}")->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->getJson(self::ADMIN."/products/{$product->id}")->assertNotFound();
        $this->getJson(self::ADMIN.'/products')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted']);

        // a deleted product's SKU / slug stay reserved (the database keeps them unique)
        $this->create($this->simplePayload($this->makeCompany('B Co'), $this->makeCategory('Z'), ['sku' => $product->sku]))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_quick_status_and_tag_endpoints(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['status' => 'draft']);
        $tags = Tag::whereIn('slug', ['hot-selling', 'featured'])->pluck('id')->all();

        $this->patchJson(self::ADMIN."/products/{$product->id}/status", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNotNull($product->fresh()->published_at);
        $this->patchJson(self::ADMIN."/products/{$product->id}/status", ['status' => 'nope'])->assertUnprocessable();

        $this->putJson(self::ADMIN."/products/{$product->id}/tags", ['tag_ids' => $tags])
            ->assertOk()->assertJsonCount(2, 'data.tags');
        $this->putJson(self::ADMIN."/products/{$product->id}/tags", ['tag_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.tags');
        $this->putJson(self::ADMIN."/products/{$product->id}/tags", ['tag_ids' => [9999]])->assertUnprocessable();
        $this->assertSame(0, DB::table('product_tag')->count());
    }

    // ------------------------------------------------------------------ list

    public function test_lean_list_row_and_filters(): void
    {
        $this->actingAsAdmin();
        $acme = $this->makeCompany('Acme');
        $beta = $this->makeCompany('Beta');
        $restorative = $this->makeCategory('Restorative');
        $composites = $this->makeSub($restorative, 'Composites');
        $endo = $this->makeCategory('Endo');

        $a = $this->makeProduct($acme, $restorative, ['name' => 'Composite Kit', 'sku' => 'CK-1', 'sub_category_id' => $composites->id, 'mrp' => 1000, 'selling_price' => 750,
            'stock' => ['standard' => 7, 'instadent' => 2]], ['best-seller']);
        $b = $this->makeProduct($beta, $endo, ['name' => 'Rotary File', 'sku' => 'RF-1', 'status' => 'draft',
            'channels' => [['code' => 'standard', 'is_active' => true]], 'stock' => ['standard' => 0]], ['hot-selling']);
        $c = $this->makeProduct($acme, $restorative, ['name' => 'Cement', 'sku' => 'CE-1', 'status' => 'inactive',
            'channels' => [['code' => 'instadent', 'is_active' => true]]]);

        $res = $this->getJson(self::ADMIN.'/products')->assertOk();
        $res->assertJsonCount(3, 'data')->assertJsonPath('counts.active', 1)->assertJsonPath('counts.draft', 1)->assertJsonPath('counts.inactive', 1);
        $row = collect($res->json('data'))->firstWhere('id', $a->id);
        $this->assertSame(['id', 'name', 'slug', 'sku', 'status', 'thumb_url', 'company', 'brand', 'category', 'sub_category', 'min_price', 'mrp', 'discount_percent',
            'variants_count', 'is_in_stock', 'stock', 'channels', 'tags', 'rating_avg', 'rating_count', 'updated_at'], array_keys($row));
        $this->assertEquals(750, $row['min_price']);
        $this->assertEquals(1000, $row['mrp']);
        $this->assertEquals(25, $row['discount_percent']);
        $this->assertSame(['standard' => 7, 'instadent' => 2], $row['stock']);   // stock per channel
        $this->assertSame('Acme', $row['company']['name']);
        $this->assertSame('Acme Brand', $row['brand']['name']);
        $this->assertSame('best-seller', $row['tags'][0]['slug']);
        $this->assertNull($row['thumb_url']);

        $ids = fn (string $query) => collect($this->getJson(self::ADMIN.'/products?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$b->id], $ids('q=RF-1'));
        $this->assertSame([$b->id], $ids('status=draft'));
        $this->assertSame([$a->id, $c->id], $ids('company_id='.$acme->id));
        $this->assertSame([$b->id], $ids('brand_id='.$beta->brands()->value('id')));
        $this->assertSame([$a->id, $c->id], $ids('category_id='.$restorative->id));
        $this->assertSame([$a->id], $ids('sub_category_id='.$composites->id));
        $this->assertSame([$b->id], $ids('tag=hot-selling'));
        $this->assertSame([$a->id, $c->id], $ids('channel=instadent'));
        $this->assertSame([$a->id, $b->id], $ids('channel=standard'));
        $this->assertSame([], $ids('q=nothing-matches'));
        $this->assertCount(1, $this->getJson(self::ADMIN.'/products?per_page=1')->json('data'));
        $this->getJson(self::ADMIN.'/products?status=bogus')->assertUnprocessable();
    }

    public function test_the_list_does_not_run_queries_per_row(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ADMIN.'/products?per_page=50')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->makeProduct($company, $category, [], ['featured']);
        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->makeProduct($company, $category, [], ['featured', 'best-seller']);
        }

        $this->assertSame($few, $count()); // lazy loading would also throw here (it is prevented in tests)
    }

    public function test_options_endpoint_gives_the_form_dropdowns(): void
    {
        $this->actingAsAdmin();
        $acme = $this->makeCompany('Acme');
        $this->makeBrand($acme, 'Second Brand')->update(['is_active' => false]);
        $this->makeCompany('Waiting Co', approved: false);

        $res = $this->getJson(self::ADMIN.'/products/options')->assertOk();
        $res->assertJsonCount(1, 'companies')                       // approved companies only
            ->assertJsonPath('companies.0.name', 'Acme')
            ->assertJsonCount(1, 'companies.0.brands')              // active brands only
            ->assertJsonCount(7, 'tags')
            ->assertJsonPath('channels.1.code', 'instadent')
            ->assertJsonPath('channels.1.requires_login', true)
            ->assertJsonPath('statuses', ['draft', 'active', 'inactive', 'archived'])
            ->assertJsonCount(7, 'content_tabs');
    }

    // ------------------------------------------------------------------ images

    public function test_admin_uploads_reorders_and_deletes_pictures(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $url = self::ADMIN."/products/{$product->id}/images";

        $res = $this->post($url, ['images' => [
            UploadedFile::fake()->image('front.jpg', 800, 800),
            UploadedFile::fake()->image('back.png', 600, 600),
        ]], ['Accept' => 'application/json'])->assertCreated();

        $images = $res->json('data');
        $this->assertCount(2, $images);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertFalse($images[1]['is_primary']);
        $this->assertStringStartsWith('http', $images[0]['url']);          // absolute urls
        $this->assertStringContainsString('/conversions/', $images[0]['thumb_url']);
        $this->assertStringContainsString('/conversions/', $images[0]['medium_url']);
        $this->assertSame('media', $images[0]['source']);

        // the second picture becomes the primary one
        $first = $images[0]['id'];
        $second = $images[1]['id'];
        $reordered = $this->putJson($url.'/order', ['ids' => [$second, $first]])->assertOk()->json('data');
        $this->assertSame([$second, $first], array_column($reordered, 'id'));
        $this->assertTrue($reordered[0]['is_primary']);
        $this->putJson($url.'/order', ['ids' => [$second]])->assertUnprocessable();            // must list every picture
        $this->putJson($url.'/order', ['ids' => [$second, 99999]])->assertUnprocessable();

        // the product detail and list show the same order
        $this->getJson(self::ADMIN."/products/{$product->id}")->assertJsonPath('data.images.0.id', $second);
        $this->assertStringContainsString('/conversions/', $this->getJson(self::ADMIN.'/products')->json('data.0.thumb_url'));

        $this->deleteJson($url.'/'.$second)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.is_primary', true);
        $this->deleteJson($url.'/'.$second)->assertNotFound();
        $this->deleteJson($url.'/999999')->assertNotFound();
    }

    public function test_picture_upload_rules_and_limit(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $other = $this->makeProduct($this->makeCompany('Other'), $this->makeCategory('Z'));
        $url = self::ADMIN."/products/{$product->id}/images";

        $this->post($url, [], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['images' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();

        $files = fn (int $n) => array_map(fn ($i) => UploadedFile::fake()->image("p$i.jpg", 100, 100), range(1, $n));
        $this->post($url, ['images' => $files(12)], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, ['images' => $files(1)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('images');

        // a picture of another product cannot be deleted through this one
        $foreign = $this->post(self::ADMIN."/products/{$other->id}/images", ['images' => $files(1)], ['Accept' => 'application/json'])->json('data.0.id');
        $this->deleteJson($url.'/'.$foreign)->assertNotFound();
    }

    public function test_remote_pictures_of_imported_products_are_listed_and_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $rowId = DB::table('product_images')->insertGetId(['product_id' => $product->id, 'url' => 'https://cdn.example.com/a.jpg', 'sort_order' => 0, 'is_primary' => true, 'type' => 'image']);

        $this->getJson(self::ADMIN."/products/{$product->id}")
            ->assertJsonPath('data.images.0.id', "r$rowId")
            ->assertJsonPath('data.images.0.url', 'https://cdn.example.com/a.jpg')
            ->assertJsonPath('data.images.0.source', 'remote');
        $this->deleteJson(self::ADMIN."/products/{$product->id}/images/r$rowId")->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ tags

    public function test_tag_crud_and_system_tags_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson(self::ADMIN.'/tags')->assertOk();
        $res->assertJsonCount(7, 'data');
        $this->assertTrue(collect($res->json('data'))->every(fn ($t) => $t['is_system'] === true));

        $tag = $this->postJson(self::ADMIN.'/tags', ['name' => 'Festival Offer', 'color' => '#ff0000'])
            ->assertCreated()->assertJsonPath('data.slug', 'festival-offer')->assertJsonPath('data.is_system', false)->json('data');
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Festival Offer'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ADMIN.'/tags', ['name' => 'Bad', 'color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('color');

        // a custom tag may be renamed (slug follows) and switched off; a system tag keeps its slug
        $this->putJson(self::ADMIN."/tags/{$tag['id']}", ['name' => 'Diwali Offer'])->assertOk()->assertJsonPath('data.slug', 'diwali-offer');
        $system = Tag::where('slug', 'best-seller')->first();
        $this->putJson(self::ADMIN."/tags/{$system->id}", ['name' => 'Top Seller', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.slug', 'best-seller')->assertJsonPath('data.is_active', false);

        // delete
        $this->deleteJson(self::ADMIN."/tags/{$system->id}")->assertUnprocessable();
        $this->assertDatabaseHas('tags', ['id' => $system->id]);
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $this->putJson(self::ADMIN."/products/{$product->id}/tags", ['tag_ids' => [$tag['id']]])->assertOk();
        $this->getJson(self::ADMIN."/tags/{$tag['id']}")->assertJsonPath('data.products_count', 1);
        $this->deleteJson(self::ADMIN."/tags/{$tag['id']}")->assertOk();
        $this->assertDatabaseMissing('tags', ['id' => $tag['id']]);
        $this->assertSame(0, DB::table('product_tag')->count());
        $this->assertGreaterThan(0, AuditLog::where('action', 'like', 'tag.%')->count());
    }

    // ------------------------------------------------------------------ quantity prices, return days, related, currency

    public function test_quantity_prices_are_saved_replaced_and_validated(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();

        $res = $this->create($this->simplePayload($company, $category, [
            'mrp' => 200, 'selling_price' => 160,
            'price_tiers' => [['min_qty' => 5, 'price' => 152], ['min_qty' => 2, 'price' => 155]],
        ]))->assertCreated();
        $res->assertJsonPath('data.variants.0.price_tiers', [['min_qty' => 2, 'price' => 155], ['min_qty' => 5, 'price' => 152]]);   // sorted by quantity
        $id = $res->json('data.id');

        // a flat update without price_tiers keeps them; sending a new list replaces them; an empty list clears them
        $this->putJson(self::ADMIN."/products/$id", ['mrp' => 200, 'selling_price' => 160])->assertOk()->assertJsonCount(2, 'data.variants.0.price_tiers');
        $this->putJson(self::ADMIN."/products/$id", ['mrp' => 200, 'selling_price' => 160, 'price_tiers' => [['min_qty' => 10, 'price' => 140]]])
            ->assertOk()->assertJsonPath('data.variants.0.price_tiers', [['min_qty' => 10, 'price' => 140]]);
        $this->putJson(self::ADMIN."/products/$id", ['mrp' => 200, 'selling_price' => 160, 'price_tiers' => []])->assertOk()->assertJsonCount(0, 'data.variants.0.price_tiers');

        $bad = fn (array $tiers) => $this->putJson(self::ADMIN."/products/$id", ['mrp' => 200, 'selling_price' => 160, 'price_tiers' => $tiers]);
        $bad([['min_qty' => 2, 'price' => 160]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers.0.price');            // not below the price
        $bad([['min_qty' => 2, 'price' => 150], ['min_qty' => 5, 'price' => 150]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers.1.price');  // larger quantity must be cheaper
        $bad([['min_qty' => 1, 'price' => 150]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers.0.min_qty');
        $bad([['min_qty' => 2, 'price' => 150], ['min_qty' => 2, 'price' => 140]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers.1.min_qty');
        $bad([['min_qty' => 2]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers.0.price');
        $bad(array_map(fn ($i) => ['min_qty' => $i + 2, 'price' => 150 - $i], range(0, 5)))->assertUnprocessable()->assertJsonValidationErrors('variants.0.price_tiers');   // max 5
    }

    public function test_options_can_have_their_own_quantity_prices(): void
    {
        $this->actingAsAdmin();
        $res = $this->create($this->simplePayload($this->makeCompany(), $this->makeCategory(), ['variants' => [
            ['name' => 'Eugenol (15ml)', 'sku' => 'E15', 'mrp' => 190, 'selling_price' => 160, 'price_tiers' => [['min_qty' => 2, 'price' => 155]]],
            ['name' => 'Eugenol (30ml)', 'sku' => 'E30', 'mrp' => 330, 'selling_price' => 210],
        ]]))->assertCreated();

        $res->assertJsonCount(1, 'data.variants.0.price_tiers')->assertJsonCount(0, 'data.variants.1.price_tiers');
    }

    public function test_return_days_default_and_related_products_and_meta(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();
        $a = $this->makeProduct($company, $category, ['name' => 'A']);
        $b = $this->makeProduct($company, $category, ['name' => 'B']);

        $res = $this->create($this->simplePayload($company, $category, [
            'name' => 'Main', 'related_ids' => [$b->id, $a->id], 'meta_title' => 'Buy Main', 'meta_description' => 'Main product',
        ]))->assertCreated();
        $res->assertJsonPath('data.return_days', 10)                       // default
            ->assertJsonPath('data.related_ids', [$b->id, $a->id])          // in the chosen order
            ->assertJsonPath('data.related.0.name', 'B')
            ->assertJsonPath('data.meta_title', 'Buy Main');
        $id = $res->json('data.id');

        $this->putJson(self::ADMIN."/products/$id", ['return_days' => 7, 'is_returnable' => true, 'related_ids' => [$a->id]])
            ->assertOk()->assertJsonPath('data.return_days', 7)->assertJsonPath('data.related_ids', [$a->id]);
        $this->putJson(self::ADMIN."/products/$id", ['return_days' => null])->assertOk()->assertJsonPath('data.return_days', 7);   // null keeps the value
        $this->putJson(self::ADMIN."/products/$id", ['related_ids' => []])->assertOk()->assertJsonCount(0, 'data.related');
        $this->putJson(self::ADMIN."/products/$id", ['return_days' => 0])->assertUnprocessable()->assertJsonValidationErrors('return_days');
        $this->putJson(self::ADMIN."/products/$id", ['related_ids' => [$id]])->assertUnprocessable()->assertJsonValidationErrors('related_ids');
        $this->putJson(self::ADMIN."/products/$id", ['related_ids' => [99999]])->assertUnprocessable()->assertJsonValidationErrors('related_ids.0');
        $this->assertDatabaseHas('products', ['id' => $id, 'return_days' => 7]);
    }

    public function test_options_have_their_own_pictures(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['sku' => 'PIC', 'variants' => [
            ['name' => 'Small', 'sku' => 'PIC-S', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'Large', 'sku' => 'PIC-L', 'mrp' => 20, 'selling_price' => 18],
        ]]);
        [$small, $large] = $product->variants()->get()->all();
        $url = self::ADMIN."/products/{$product->id}/images";
        $file = fn (string $n = 'a.jpg') => UploadedFile::fake()->image($n, 600, 600);
        $json = ['Accept' => 'application/json'];

        $this->post($url, ['images' => [$file('gallery.jpg')]], $json)->assertCreated();
        $this->post($url, ['images' => [$file('s1.jpg'), $file('s2.jpg')], 'variant_id' => $small->id], $json)->assertCreated();
        $images = $this->post($url, ['images' => [$file('l1.jpg')], 'variant_id' => $large->id], $json)->assertCreated()->json('data');

        $byVariant = collect($images)->groupBy(fn ($i) => $i['variant_id'] ?? 'product');
        $this->assertCount(1, $byVariant['product']);
        $this->assertCount(2, $byVariant[$small->id]);
        $this->assertCount(1, $byVariant[$large->id]);
        $this->assertTrue($byVariant[$small->id][0]['is_primary']);        // each gallery has its own primary picture
        $this->assertTrue($byVariant['product'][0]['is_primary']);
        $this->assertTrue($byVariant[$large->id][0]['is_primary']);

        // reorder works inside one gallery and refuses a mix
        [$first, $second] = $byVariant[$small->id]->pluck('id')->all();
        $reordered = $this->putJson($url.'/order', ['ids' => [$second, $first]])->assertOk()->json('data');
        $smallNow = collect($reordered)->where('variant_id', $small->id)->pluck('id')->values()->all();
        $this->assertSame([$second, $first], $smallNow);
        $this->putJson($url.'/order', ['ids' => [$second, $byVariant['product'][0]['id']]])->assertUnprocessable();
        $this->putJson($url.'/order', ['ids' => [$second]])->assertUnprocessable();

        // an option of another product is refused
        $other = $this->makeProduct($this->makeCompany('Other'), $this->makeCategory('Z'));
        $this->post($url, ['images' => [$file()], 'variant_id' => $other->variants()->value('id')], $json)->assertUnprocessable()->assertJsonValidationErrors('variant_id');

        // the limit is per gallery: 12 for the option, the product gallery is not affected
        $many = array_map(fn ($i) => $file("m$i.jpg"), range(1, 11));
        $this->post($url, ['images' => $many, 'variant_id' => $large->id], $json)->assertCreated();
        $this->post($url, ['images' => [$file()], 'variant_id' => $large->id], $json)->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->post($url, ['images' => [$file()]], $json)->assertCreated();

        // removing the option removes its pictures
        $count = fn () => $this->getJson(self::ADMIN."/products/{$product->id}")->json('data.images');
        $before = count($count());
        $this->putJson(self::ADMIN."/products/{$product->id}", ['variants' => [['id' => $small->id, 'name' => 'Small', 'sku' => 'PIC-S', 'mrp' => 10, 'selling_price' => 9]]])->assertOk();
        $this->assertSame($before - 12, count($count()));       // the 12 pictures of "Large"
        $this->assertTrue(collect($count())->every(fn ($i) => $i['variant_id'] !== $large->id));
    }

    public function test_every_option_needs_a_name_and_a_flat_price_names_the_option_after_the_product(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany();
        $category = $this->makeCategory();

        // an explicit option without a name is refused, even when it is the only one
        $this->create($this->simplePayload($company, $category, ['variants' => [['sku' => 'X1', 'mrp' => 10, 'selling_price' => 9]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.name');
        // the flat price shortcut creates one option named like the product
        $this->create($this->simplePayload($company, $category, ['name' => 'Waldent Airotor', 'sku' => 'WA-1']))
            ->assertCreated()->assertJsonPath('data.variants.0.name', 'Waldent Airotor');
    }

    public function test_pictures_can_be_moved_anywhere_even_the_imported_ones(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory());
        $r1 = DB::table('product_images')->insertGetId(['product_id' => $product->id, 'url' => 'https://cdn.example.com/1.jpg', 'sort_order' => 1, 'type' => 'image']);
        $r2 = DB::table('product_images')->insertGetId(['product_id' => $product->id, 'url' => 'https://cdn.example.com/2.jpg', 'sort_order' => 2, 'type' => 'image']);
        $url = self::ADMIN."/products/{$product->id}/images";

        // an uploaded picture goes to the end, after the imported ones
        $list = $this->post($url, ['images' => [UploadedFile::fake()->image('new.jpg', 500, 500)]], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $mediaId = $list[2]['id'];
        $this->assertSame(["r$r1", "r$r2"], [$list[0]['id'], $list[1]['id']]);
        $this->assertSame('media', $list[2]['source']);

        // the last (uploaded) one becomes primary, then an imported one moves to the end
        $list = $this->putJson($url.'/order', ['ids' => [$mediaId, "r$r1", "r$r2"]])->assertOk()->json('data');
        $this->assertSame([$mediaId, "r$r1", "r$r2"], array_column($list, 'id'));
        $this->assertTrue($list[0]['is_primary']);
        $list = $this->putJson($url.'/order', ['ids' => ["r$r2", $mediaId, "r$r1"]])->assertOk()->json('data');
        $this->assertSame(["r$r2", $mediaId, "r$r1"], array_column($list, 'id'));
        $this->assertSame('https://cdn.example.com/2.jpg', $this->getJson(self::ADMIN.'/products')->json('data.0.thumb_url'));   // the list uses the new primary

        $this->putJson($url.'/order', ['ids' => ["r$r2", $mediaId]])->assertUnprocessable();       // must list every picture
        $this->putJson($url.'/order', ['ids' => ['x1', $mediaId]])->assertUnprocessable();         // bad id
    }

    public function test_categories_and_tags_lists_are_paginated(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 18) as $i) {
            $this->makeCategory(sprintf('Category %02d', $i));
        }
        $this->makeSub(\App\Domain\Catalog\Models\Category::first(), 'A sub');

        $res = $this->getJson(self::ADMIN.'/categories?per_page=5&page=2')->assertOk();
        $res->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 18)->assertJsonPath('meta.last_page', 4)->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('counts.categories', 18)->assertJsonPath('counts.sub_categories', 1)->assertJsonPath('counts.top', 0);
        $this->assertCount(15, $this->getJson(self::ADMIN.'/categories')->json('data'));          // default page size 15
        $this->getJson(self::ADMIN.'/categories?per_page=500')->assertUnprocessable();

        foreach (range(1, 12) as $i) {
            $this->postJson(self::ADMIN.'/tags', ['name' => sprintf('Extra tag %02d', $i)])->assertCreated();
        }
        $this->getJson(self::ADMIN.'/tags?per_page=10&page=2')->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('meta.total', 19);   // 7 system + 12
        $this->getJson(self::ADMIN.'/tags?q=extra tag 1')->assertOk()->assertJsonCount(3, 'data');   // the names that START with it: 10, 11, 12
    }

    public function test_at_least_one_option_must_stay_active_and_the_default_follows_the_active_ones(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct($this->makeCompany(), $this->makeCategory(), ['sku' => 'RB', 'variants' => [
            ['name' => 'Red', 'sku' => 'RB-R', 'mrp' => 10, 'selling_price' => 9, 'is_default' => true],
            ['name' => 'Blue', 'sku' => 'RB-B', 'mrp' => 20, 'selling_price' => 18],
        ]]);
        [$red, $blue] = $product->variants()->get()->all();
        $row = fn ($v, array $over = []) => array_merge(['id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'mrp' => 20, 'selling_price' => 18], $over);
        $url = self::ADMIN."/products/{$product->id}";

        // every option switched off: refused
        $this->putJson($url, ['variants' => [$row($red, ['is_active' => false]), $row($blue, ['is_active' => false])]])
            ->assertUnprocessable()->assertJsonValidationErrors('variants');
        $this->assertSame(2, $product->variants()->where('is_active', true)->count());

        // the default option is switched off: the other active one becomes the default, and the starting price ignores the inactive one
        $res = $this->putJson($url, ['variants' => [$row($red, ['is_active' => false, 'is_default' => true, 'selling_price' => 5, 'mrp' => 10]), $row($blue)]])->assertOk();
        $res->assertJsonPath('data.variants.0.is_default', false)->assertJsonPath('data.variants.1.is_default', true)->assertJsonPath('data.min_price', 18);
    }

    public function test_the_form_options_carry_the_currency(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::ADMIN.'/products/options')->assertOk()->assertJsonPath('currency', ['code' => 'PKR', 'symbol' => 'Rs']);
    }
}

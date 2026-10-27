<?php

namespace Tests\Feature;

use App\Domain\Catalog\CategoryTree;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';
    private const STORE = '/api/v1/store';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeCategory(array $attrs = []): Category
    {
        $name = $attrs['name'] ?? 'Cat '.uniqid();
        $category = new Category(array_merge(['name' => $name], $attrs));
        $category->slug = Category::uniqueSlug($name);
        $category->save();

        return $category;
    }

    private function makeSub(Category $category, array $attrs = []): SubCategory
    {
        $name = $attrs['name'] ?? 'Sub '.uniqid();
        $sub = new SubCategory(array_merge(['name' => $name], $attrs));
        $sub->category_id = $category->id;
        $sub->slug = SubCategory::uniqueSlug($name);
        $sub->save();

        return $sub;
    }

    private function makeChild(SubCategory $parent, array $attrs = []): SubCategory
    {
        $name = $attrs['name'] ?? 'Child '.uniqid();
        $child = new SubCategory(array_merge(['name' => $name], $attrs));
        $child->category_id = $parent->category_id;
        $child->parent_id = $parent->id;
        $child->slug = SubCategory::uniqueSlug($name);
        $child->save();

        return $child;
    }

    /** A product row (the Product model does not exist yet); foreign keys to company/brand are skipped. */
    private function makeProduct(Category $category, ?SubCategory $sub = null): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('products')->insert([
            'company_id' => 1, 'brand_id' => 1, 'category_id' => $category->id, 'sub_category_id' => $sub?->id,
            'name' => 'Product', 'slug' => 'product-'.uniqid(), 'sku' => 'SKU-'.uniqid(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ---------------------------------------------------------------- admin: categories

    public function test_admin_endpoints_need_a_login_but_the_customer_api_does_not(): void
    {
        $this->getJson(self::ADMIN.'/categories')->assertUnauthorized();
        $this->getJson(self::ADMIN.'/categories/options')->assertUnauthorized();
        $this->postJson(self::ADMIN.'/categories', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson(self::ADMIN.'/sub-categories/1')->assertUnauthorized();

        $this->getJson(self::STORE.'/categories')->assertOk();
    }

    public function test_admin_creates_a_category_with_defaults(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson(self::ADMIN.'/categories', ['name' => 'Endodontics'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Endodontics')
            ->assertJsonPath('data.slug', 'endodontics')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.icon_url', null)
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.sub_categories_count', 0)
            ->assertJsonPath('data.products_count', 0);

        $this->assertDatabaseHas('categories', ['id' => $res->json('data.id'), 'slug' => 'endodontics']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.created', 'subject_id' => $res->json('data.id')]);
    }

    public function test_name_is_required_and_unique(): void
    {
        $this->actingAsAdmin();
        $this->makeCategory(['name' => 'Endodontics']);

        $this->postJson(self::ADMIN.'/categories', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ADMIN.'/categories', ['name' => 'Endodontics'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ADMIN.'/categories', ['name' => 'Valid', 'color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('color');
    }

    public function test_icon_and_image_come_back_as_absolute_urls(): void
    {
        $this->actingAsAdmin();

        $res = $this->post(self::ADMIN.'/categories', [
            'name' => 'Orthodontics',
            'icon' => UploadedFile::fake()->image('icon.png', 120, 120),
            'image' => UploadedFile::fake()->image('image.jpg', 600, 400),
        ], ['Accept' => 'application/json'])->assertCreated();

        foreach (['icon_url', 'icon_thumb_url', 'image_url'] as $key) {
            $this->assertStringStartsWith('http', (string) $res->json("data.$key"), $key);
        }

        // The customer API shows the same absolute URLs.
        $store = $this->getJson(self::STORE.'/categories')->assertOk()->json('data.0');
        $this->assertStringStartsWith('http', $store['icon_url']);
        $this->assertStringStartsWith('http', $store['image_url']);
    }

    public function test_update_with_files_uses_post_with_method_override_and_remove_flags_clear_a_file(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => 'Materials']);

        $this->post(self::ADMIN.'/categories/'.$category->id, [
            '_method' => 'PUT',
            'icon' => UploadedFile::fake()->image('icon.png', 100, 100),
            'image' => UploadedFile::fake()->image('image.png', 300, 200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Materials');
        $this->assertCount(1, $category->fresh()->getMedia('icon'));
        $this->assertCount(1, $category->fresh()->getMedia('image'));

        // A second upload replaces the first (single file).
        $this->post(self::ADMIN.'/categories/'.$category->id, [
            '_method' => 'PUT', 'icon' => UploadedFile::fake()->image('icon2.png', 100, 100),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, $category->fresh()->getMedia('icon'));

        $this->post(self::ADMIN.'/categories/'.$category->id, ['_method' => 'PUT', 'remove_icon' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.icon_url', null)
            ->assertJson(fn ($json) => $json->has('data.image_url')->etc());
        $this->assertCount(0, $category->fresh()->getMedia('icon'));
        $this->assertCount(1, $category->fresh()->getMedia('image'));

        $this->putJson(self::ADMIN.'/categories/'.$category->id, ['remove_image' => true])->assertOk()->assertJsonPath('data.image_url', null);
        $this->assertCount(0, $category->fresh()->getMedia('image'));
    }

    public function test_slug_is_unique_and_follows_the_name(): void
    {
        $this->actingAsAdmin();

        $a = $this->postJson(self::ADMIN.'/categories', ['name' => 'Oral Care'])->assertCreated()->json('data');
        $b = $this->postJson(self::ADMIN.'/categories', ['name' => 'Oral Care!'])->assertCreated()->json('data');
        $this->assertSame('oral-care', $a['slug']);
        $this->assertSame('oral-care-2', $b['slug']);

        // Renaming regenerates the slug; saving other fields keeps it.
        $this->putJson(self::ADMIN.'/categories/'.$a['id'], ['name' => 'Oral Hygiene'])->assertOk()->assertJsonPath('data.slug', 'oral-hygiene');
        $this->putJson(self::ADMIN.'/categories/'.$a['id'], ['sort_order' => 5])->assertOk()->assertJsonPath('data.slug', 'oral-hygiene');

        // The old slug is free again; a soft-deleted slug is never reused.
        $this->putJson(self::ADMIN.'/categories/'.$b['id'], ['name' => 'Oral Care'])->assertOk()->assertJsonPath('data.slug', 'oral-care');
        $this->deleteJson(self::ADMIN.'/categories/'.$a['id'])->assertOk();
        $this->postJson(self::ADMIN.'/categories', ['name' => 'Oral Hygiene'])->assertCreated()->assertJsonPath('data.slug', 'oral-hygiene-2');
    }

    public function test_admin_list_has_sub_categories_and_counts_and_search(): void
    {
        $this->actingAsAdmin();
        $endo = $this->makeCategory(['name' => 'Endodontics', 'sort_order' => 2]);
        $ortho = $this->makeCategory(['name' => 'Orthodontics', 'sort_order' => 1, 'is_active' => false]);
        $files = $this->makeSub($endo, ['name' => 'Rotary Files', 'sort_order' => 2]);
        $gutta = $this->makeSub($endo, ['name' => 'Gutta Percha', 'sort_order' => 1]);
        $this->makeProduct($endo, $files);
        $this->makeProduct($endo, $files);
        $this->makeProduct($endo);

        $data = $this->getJson(self::ADMIN.'/categories')->assertOk()->json('data');

        $this->assertSame(['Orthodontics', 'Endodontics'], array_column($data, 'name')); // inactive ones are listed too, ordered by sort_order
        $this->assertSame(0, $data[0]['sub_categories_count']);
        $this->assertSame(2, $data[1]['sub_categories_count']);
        $this->assertSame(3, $data[1]['products_count']);
        $this->assertSame(['Gutta Percha', 'Rotary Files'], array_column($data[1]['sub_categories'], 'name'));
        $this->assertSame([0, 2], array_column($data[1]['sub_categories'], 'products_count'));

        $this->assertSame(['Endodontics'], array_column($this->getJson(self::ADMIN.'/categories?q=gutta')->json('data'), 'name'));
        $this->assertSame(['Orthodontics'], array_column($this->getJson(self::ADMIN.'/categories?q=ortho')->json('data'), 'name'));
    }

    public function test_admin_list_does_not_run_a_query_per_row(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 6) as $i) {
            $category = $this->makeCategory();
            $this->makeSub($category);
            $this->makeSub($category);
        }

        DB::enableQueryLog();
        $this->getJson(self::ADMIN.'/categories')->assertOk();
        $this->assertLessThan(12, count(DB::getQueryLog()));
    }

    public function test_options_lists_only_active_categories_and_sub_categories(): void
    {
        $this->actingAsAdmin();
        $endo = $this->makeCategory(['name' => 'Endodontics', 'sort_order' => 1]);
        $this->makeCategory(['name' => 'Hidden', 'is_active' => false, 'sort_order' => 2]);
        $this->makeSub($endo, ['name' => 'Rotary Files', 'sort_order' => 1]);
        $this->makeSub($endo, ['name' => 'Old Files', 'is_active' => false]);

        $this->getJson(self::ADMIN.'/categories/options')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $endo->id,
                'name' => 'Endodontics',
                'sub_categories' => [['id' => $endo->subCategories()->where('name', 'Rotary Files')->value('id'), 'name' => 'Rotary Files', 'children' => []]],
            ]]]);
    }

    public function test_admin_can_show_update_flags_and_delete_an_empty_category(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => 'Wax']);

        $this->getJson(self::ADMIN.'/categories/'.$category->id)->assertOk()->assertJsonPath('data.name', 'Wax');
        $this->putJson(self::ADMIN.'/categories/'.$category->id, ['is_active' => false, 'sort_order' => 9])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sort_order', 9);

        $this->deleteJson(self::ADMIN.'/categories/'.$category->id)->assertOk();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->getJson(self::ADMIN.'/categories/'.$category->id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.deleted', 'subject_id' => $category->id]);
    }

    // ---------------------------------------------------------------- admin: sub-categories

    public function test_admin_crud_for_sub_categories(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => 'Endodontics']);
        $other = $this->makeCategory(['name' => 'Oral Surgery']);

        $created = $this->post(self::ADMIN.'/categories/'.$category->id.'/sub-categories', [
            'name' => 'Rotary Files',
            'description' => 'NiTi files',
            'icon' => UploadedFile::fake()->image('i.png', 80, 80),
            'image' => UploadedFile::fake()->image('p.png', 300, 200),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rotary Files')
            ->assertJsonPath('data.slug', 'rotary-files')
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category.name', 'Endodontics')
            ->assertJsonPath('data.is_active', true)
            ->json('data');
        $this->assertStringStartsWith('http', $created['icon_url']);
        $this->assertStringStartsWith('http', $created['image_url']);

        $id = $created['id'];
        $this->getJson(self::ADMIN.'/sub-categories/'.$id)->assertOk()->assertJsonPath('data.description', 'NiTi files');

        // Same name twice in one category is refused, but another category may reuse it.
        $this->postJson(self::ADMIN.'/categories/'.$category->id.'/sub-categories', ['name' => 'Rotary Files'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::ADMIN.'/categories/'.$other->id.'/sub-categories', ['name' => 'Rotary Files'])
            ->assertCreated()->assertJsonPath('data.slug', 'rotary-files-2');

        // Rename (slug follows), flags, remove_icon via POST + _method.
        $this->putJson(self::ADMIN.'/sub-categories/'.$id, ['name' => 'Rotary Systems', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.slug', 'rotary-systems')->assertJsonPath('data.is_active', false);
        $this->post(self::ADMIN.'/sub-categories/'.$id, ['_method' => 'PUT', 'remove_icon' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.icon_url', null);
        $this->assertCount(1, SubCategory::find($id)->getMedia('image'));

        $this->deleteJson(self::ADMIN.'/sub-categories/'.$id)->assertOk();
        $this->assertSoftDeleted('sub_categories', ['id' => $id]);
        $this->getJson(self::ADMIN.'/sub-categories/'.$id)->assertNotFound();
    }

    public function test_a_sub_category_needs_an_existing_category(): void
    {
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN.'/categories/999999/sub-categories', ['name' => 'Ghost'])->assertNotFound();
    }

    // ---------------------------------------------------------------- delete rules

    public function test_a_category_with_sub_categories_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory();
        $sub = $this->makeSub($category);

        $this->deleteJson(self::ADMIN.'/categories/'.$category->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This category has 1 sub-category. Deactivate it instead of deleting.');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);

        // Once the sub-category is gone the category can go too.
        $this->deleteJson(self::ADMIN.'/sub-categories/'.$sub->id)->assertOk();
        $this->deleteJson(self::ADMIN.'/categories/'.$category->id)->assertOk();
    }

    public function test_a_category_or_sub_category_with_products_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $withProducts = $this->makeCategory();
        $this->makeProduct($withProducts);

        $category = $this->makeCategory();
        $sub = $this->makeSub($category);
        $this->makeProduct($category, $sub);

        $this->deleteJson(self::ADMIN.'/categories/'.$withProducts->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This category has products. Deactivate it instead of deleting.');
        $this->deleteJson(self::ADMIN.'/sub-categories/'.$sub->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This sub-category has products. Deactivate it instead of deleting.');

        $this->assertDatabaseHas('categories', ['id' => $withProducts->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('sub_categories', ['id' => $sub->id, 'deleted_at' => null]);

        // Deactivating is the way out.
        $this->putJson(self::ADMIN.'/sub-categories/'.$sub->id, ['is_active' => false])->assertOk();
    }

    // ---------------------------------------------------------------- customer API

    public function test_store_tree_has_the_simple_shape_and_hides_everything_inactive(): void
    {
        $endo = $this->makeCategory(['name' => 'Endodontics', 'description' => 'Root canal', 'sort_order' => 1]);
        $this->makeSub($endo, ['name' => 'Rotary Files', 'sort_order' => 2]);
        $this->makeSub($endo, ['name' => 'Gutta Percha', 'sort_order' => 1]);
        $this->makeSub($endo, ['name' => 'Old Files', 'is_active' => false]);

        $hidden = $this->makeCategory(['name' => 'Hidden', 'is_active' => false, 'sort_order' => 2]);
        $this->makeSub($hidden, ['name' => 'Child of hidden']);

        $res = $this->getJson(self::STORE.'/categories')->assertOk();
        $data = $res->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(['id', 'name', 'slug', 'description', 'is_top', 'icon_url', 'image_url', 'sub_categories'], array_keys($data[0]));
        $this->assertSame(['id', 'name', 'slug', 'icon_url', 'image_url', 'children'], array_keys($data[0]['sub_categories'][0]));
        $this->assertSame(['Gutta Percha', 'Rotary Files'], array_column($data[0]['sub_categories'], 'name'));
        $this->assertNull($data[0]['icon_url']);
        $this->assertNull($data[0]['sub_categories'][0]['image_url']);
        $this->assertStringNotContainsString('Hidden', $res->getContent());
        $this->assertStringNotContainsString('Old Files', $res->getContent());
    }

    public function test_store_tree_is_ordered_by_sort_order_then_name(): void
    {
        $this->makeCategory(['name' => 'Zebra', 'sort_order' => 1]);
        $this->makeCategory(['name' => 'Beta', 'sort_order' => 2]);
        $this->makeCategory(['name' => 'Alpha', 'sort_order' => 2]);

        $this->assertSame(['Zebra', 'Alpha', 'Beta'], array_column($this->getJson(self::STORE.'/categories')->json('data'), 'name'));
    }

    public function test_one_category_by_slug(): void
    {
        $endo = $this->makeCategory(['name' => 'Endodontics']);
        $this->makeSub($endo, ['name' => 'Rotary Files']);
        $this->makeSub($endo, ['name' => 'Hidden Files', 'is_active' => false]);
        $this->makeCategory(['name' => 'Off', 'is_active' => false]);

        $this->getJson(self::STORE.'/categories/endodontics')
            ->assertOk()
            ->assertJsonPath('data.name', 'Endodontics')
            ->assertJsonCount(1, 'data.sub_categories')
            ->assertJsonPath('data.sub_categories.0.name', 'Rotary Files');

        $this->getJson(self::STORE.'/categories/off')->assertNotFound();
        $this->getJson(self::STORE.'/categories/does-not-exist')->assertNotFound();
    }

    public function test_deactivating_in_the_admin_hides_it_from_customers_straight_away(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => 'Endodontics']);
        $sub = $this->makeSub($category, ['name' => 'Rotary Files']);

        $this->assertCount(1, $this->getJson(self::STORE.'/categories')->json('data.0.sub_categories')); // fills the cache

        $this->putJson(self::ADMIN.'/sub-categories/'.$sub->id, ['is_active' => false])->assertOk();
        $this->assertCount(0, $this->getJson(self::STORE.'/categories')->json('data.0.sub_categories'));

        $this->putJson(self::ADMIN.'/categories/'.$category->id, ['is_active' => false])->assertOk();
        $this->assertSame([], $this->getJson(self::STORE.'/categories')->json('data'));
        $this->getJson(self::STORE.'/categories/endodontics')->assertNotFound();
    }

    public function test_the_customer_tree_is_cached_and_every_admin_write_clears_it(): void
    {
        $this->actingAsAdmin();
        $category = $this->makeCategory(['name' => 'First']);

        $this->getJson(self::STORE.'/categories')->assertJsonCount(1, 'data');

        // A change that bypasses the admin API is not seen: the tree comes from the cache.
        $this->makeCategory(['name' => 'Behind the cache']);
        $this->getJson(self::STORE.'/categories')->assertJsonCount(1, 'data');

        // Every admin write clears it.
        $writes = [
            fn () => $this->postJson(self::ADMIN.'/categories', ['name' => 'Created']),
            fn () => $this->putJson(self::ADMIN.'/categories/'.$category->id, ['description' => 'x']),
            fn () => $this->postJson(self::ADMIN.'/categories/'.$category->id.'/sub-categories', ['name' => 'Child']),
            fn () => $this->deleteJson(self::ADMIN.'/categories/'.$this->makeCategory(['name' => 'Temp'])->id),
        ];
        foreach ($writes as $write) {
            $this->getJson(self::STORE.'/categories'); // refill
            $this->assertTrue(Cache::has(CategoryTree::KEY));
            $write()->assertSuccessful();
            $this->assertFalse(Cache::has(CategoryTree::KEY), 'cache should be cleared');
        }

        $names = array_column($this->getJson(self::STORE.'/categories')->json('data'), 'name');
        $this->assertContains('Behind the cache', $names);
        $this->assertContains('Created', $names);
        $this->assertNotContains('Temp', $names);
    }

    // ---------------------------------------------------------------- seeder

    public function test_the_seeder_is_idempotent_and_keeps_admin_edits(): void
    {
        $this->seed(CategorySeeder::class);
        $categories = Category::count();
        $subs = SubCategory::count();
        $this->assertSame(13, $categories);
        $this->assertGreaterThan(40, $subs);

        $endo = Category::where('slug', 'endodontics')->first();
        $this->assertSame('Endodontics', $endo->name);
        $this->assertCount(9, $endo->subCategories);
        $this->assertSame(12, Category::where('is_top', true)->count());
        $brackets = SubCategory::where('slug', 'brackets')->first();
        $this->assertCount(3, $brackets->children);
        $this->assertSame($brackets->category_id, $brackets->children->first()->category_id);
        $this->assertNull($endo->getFirstMedia('icon'));

        $endo->update(['is_active' => false]);
        $this->seed(CategorySeeder::class);

        $this->assertSame($categories, Category::count());
        $this->assertSame($subs, SubCategory::count());
        $this->assertFalse($endo->fresh()->is_active);
        $this->assertCount(9, $endo->fresh()->subCategories); // children are not first-level sub-categories
    }

    // ---------------------------------------------------------------- top categories (home screen)

    public function test_at_most_12_categories_can_be_top_and_the_flag_is_free_again_after_unticking(): void
    {
        $this->actingAsAdmin();
        $made = [];
        for ($i = 1; $i <= 12; $i++) {
            $made[] = $this->postJson(self::ADMIN.'/categories', ['name' => "Top $i", 'is_top' => true])
                ->assertCreated()->assertJsonPath('data.is_top', true)->json('data.id');
        }

        $this->postJson(self::ADMIN.'/categories', ['name' => 'Top 13', 'is_top' => true])
            ->assertStatus(422)->assertJsonValidationErrors('is_top');
        $thirteen = $this->makeCategory(['name' => 'Thirteen']);
        $this->putJson(self::ADMIN."/categories/{$thirteen->id}", ['is_top' => true])->assertStatus(422);
        $this->assertFalse($thirteen->fresh()->is_top);

        // an already-top category can be saved again with is_top=true while the list is full
        $this->putJson(self::ADMIN."/categories/{$made[0]}", ['is_top' => true, 'description' => 'x'])->assertOk();

        $this->putJson(self::ADMIN."/categories/{$made[0]}", ['is_top' => false])->assertOk()->assertJsonPath('data.is_top', false);
        $this->putJson(self::ADMIN."/categories/{$thirteen->id}", ['is_top' => true])->assertOk();
    }

    public function test_top_filter_returns_only_active_top_categories_in_order(): void
    {
        $this->makeCategory(['name' => 'B top', 'is_top' => true, 'sort_order' => 2]);
        $this->makeCategory(['name' => 'A top', 'is_top' => true, 'sort_order' => 1]);
        $this->makeCategory(['name' => 'Plain', 'sort_order' => 3]);
        $this->makeCategory(['name' => 'Hidden top', 'is_top' => true, 'is_active' => false, 'sort_order' => 4]);

        $this->getJson(self::STORE.'/categories?top=1')->assertOk()
            ->assertJsonPath('data.0.name', 'A top')->assertJsonPath('data.1.name', 'B top')->assertJsonCount(2, 'data');
        $this->getJson(self::STORE.'/categories')->assertJsonCount(3, 'data');
    }

    // ---------------------------------------------------------------- sub-sub-categories

    public function test_admin_adds_a_sub_sub_category_with_an_icon_under_a_sub_category(): void
    {
        $this->actingAsAdmin();
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);

        $res = $this->post(self::ADMIN."/sub-categories/{$pistol->id}/children", [
            'name' => '9mm', 'icon' => UploadedFile::fake()->image('i.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('data.parent_id', $pistol->id)->assertJsonPath('data.category_id', $gun->id);
        $this->assertNotNull($res->json('data.icon_url'));
        $this->assertSame('9mm', SubCategory::find($res->json('data.id'))->slug);

        // the category's first level only has the parent; the child hangs under it
        $this->getJson(self::ADMIN.'/categories')->assertOk()
            ->assertJsonPath('data.0.sub_categories_count', 1)
            ->assertJsonPath('data.0.sub_categories.0.name', 'Pistol')
            ->assertJsonPath('data.0.sub_categories.0.children_count', 1)
            ->assertJsonPath('data.0.sub_categories.0.children.0.name', '9mm');
    }

    public function test_a_sub_sub_category_cannot_have_children_and_names_are_unique_among_siblings(): void
    {
        $this->actingAsAdmin();
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);
        $rifle = $this->makeSub($gun, ['name' => 'Rifle']);
        $nine = $this->makeChild($pistol, ['name' => '9mm']);

        $this->postJson(self::ADMIN."/sub-categories/{$nine->id}/children", ['name' => 'Glock'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->postJson(self::ADMIN."/sub-categories/{$pistol->id}/children", ['name' => '9mm'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson(self::ADMIN."/sub-categories/{$rifle->id}/children", ['name' => '9mm'])->assertCreated();
        $this->postJson(self::ADMIN.'/sub-categories/999999/children', ['name' => 'x'])->assertNotFound();
    }

    public function test_a_sub_category_with_children_cannot_be_deleted_but_a_child_can(): void
    {
        $this->actingAsAdmin();
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);
        $nine = $this->makeChild($pistol, ['name' => '9mm']);

        $this->deleteJson(self::ADMIN."/sub-categories/{$pistol->id}")
            ->assertStatus(422)->assertJsonValidationErrors('category')
            ->assertJsonPath('errors.category.0', 'This sub-category has 1 sub-category. Deactivate it instead of deleting.');

        $this->makeProduct($gun, $nine);
        $this->deleteJson(self::ADMIN."/sub-categories/{$nine->id}")->assertStatus(422);

        DB::table('products')->delete();
        $this->deleteJson(self::ADMIN."/sub-categories/{$nine->id}")->assertOk();
        $this->deleteJson(self::ADMIN."/sub-categories/{$pistol->id}")->assertOk();
    }

    public function test_a_parent_sub_category_counts_the_products_of_its_children(): void
    {
        $this->actingAsAdmin();
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);
        $nine = $this->makeChild($pistol, ['name' => '9mm']);
        $this->makeProduct($gun, $nine);
        $this->makeProduct($gun, $nine);
        $this->makeProduct($gun, $pistol);

        $this->getJson(self::ADMIN.'/categories')
            ->assertJsonPath('data.0.products_count', 3)
            ->assertJsonPath('data.0.sub_categories.0.products_count', 3)
            ->assertJsonPath('data.0.sub_categories.0.children.0.products_count', 2);
    }

    public function test_admin_search_finds_a_sub_sub_category_and_options_nest_children(): void
    {
        $this->actingAsAdmin();
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);
        $nine = $this->makeChild($pistol, ['name' => '9mm']);
        $this->makeChild($pistol, ['name' => 'Retired', 'is_active' => false]);
        $this->makeCategory(['name' => 'Other']);

        $this->getJson(self::ADMIN.'/categories?q=9mm')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Gun');
        $this->getJson(self::ADMIN.'/categories/options')
            ->assertJsonPath('data.0.sub_categories.0.children', [['id' => $nine->id, 'name' => '9mm']]);
    }

    public function test_store_tree_nests_active_children_in_order(): void
    {
        $gun = $this->makeCategory(['name' => 'Gun']);
        $pistol = $this->makeSub($gun, ['name' => 'Pistol']);
        $this->makeChild($pistol, ['name' => '9mm', 'sort_order' => 2]);
        $this->makeChild($pistol, ['name' => '45 ACP', 'sort_order' => 1]);
        $this->makeChild($pistol, ['name' => 'Retired', 'is_active' => false]);
        $rifle = $this->makeSub($gun, ['name' => 'Rifle', 'is_active' => false]);
        $this->makeChild($rifle, ['name' => 'Under hidden parent']);

        $res = $this->getJson(self::STORE.'/categories')->assertOk();
        $children = $res->json('data.0.sub_categories.0.children');
        $this->assertSame(['45 ACP', '9mm'], array_column($children, 'name'));
        $this->assertSame(['id', 'name', 'slug', 'icon_url', 'image_url'], array_keys($children[0]));
        $this->assertStringNotContainsString('Under hidden parent', $res->getContent());
        $this->assertStringNotContainsString('Retired', $res->getContent());

        $this->getJson(self::STORE.'/categories/gun')->assertOk()->assertJsonCount(2, 'data.sub_categories.0.children');
    }
}

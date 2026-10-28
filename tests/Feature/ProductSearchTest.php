<?php

namespace Tests\Feature;

use App\Domain\Company\Models\Company;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * Searches that use the MySQL FULLTEXT indexes. InnoDB only indexes COMMITTED rows, so these tests cannot run inside the
 * transaction the other tests use: they truncate the tables instead (and clean up again afterwards).
 */
class ProductSearchTest extends TestCase
{
    use BuildsCatalog, DatabaseTruncation;

    private const ADMIN = '/api/v1/admin';
    private const STORE = '/api/v1/store';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    private function slugs(string $query): array
    {
        return collect($this->getJson(self::STORE.'/products?q='.urlencode($query))->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();
    }

    private function catalogue(): void
    {
        $acme = $this->makeCompany('Acme Dental Supplies');
        $waldent = $this->makeBrand($acme, 'Waldent');
        $category = $this->makeCategory();
        $this->makeProduct($acme, $category, ['name' => 'Prime-Dent Hybrid Composite Kit', 'short_description' => 'Light cured restorative', 'sku' => 'PD-100']);
        $this->makeProduct($acme, $category, ['name' => 'Rotary Endo File Set', 'short_description' => 'Nickel titanium files', 'sku' => 'RF-200', 'brand_id' => $waldent->id]);
        $this->makeProduct($acme, $category, ['name' => '3M ESPE Cavit G Filling', 'short_description' => 'Temporary filling material', 'sku' => 'CV-300']);
    }

    public function test_the_store_search_finds_words_anywhere_in_the_name_and_description(): void
    {
        $this->catalogue();

        $this->assertSame(['prime-dent-hybrid-composite-kit'], $this->slugs('composite'));      // a word in the middle of the name
        $this->assertSame(['prime-dent-hybrid-composite-kit'], $this->slugs('comp'));           // the start of a word
        $this->assertSame(['prime-dent-hybrid-composite-kit'], $this->slugs('hybrid composite'));   // every word must match
        $this->assertSame([], $this->slugs('hybrid endo'));
        $this->assertSame(['prime-dent-hybrid-composite-kit'], $this->slugs('dent'));           // "Prime-Dent" is two words
        $this->assertSame(['prime-dent-hybrid-composite-kit'], $this->slugs('restorative'));    // short description
        $this->assertSame(['rotary-endo-file-set'], $this->slugs('titanium'));
        $this->assertSame([], $this->slugs('zebra'));
    }

    public function test_the_store_search_also_matches_the_sku_start_and_the_brand_start(): void
    {
        $this->catalogue();

        $this->assertSame(['rotary-endo-file-set'], $this->slugs('RF-2'));
        $this->assertSame(['rotary-endo-file-set'], $this->slugs('walde'));                      // brand "Waldent"
        $this->assertCount(2, $this->slugs('Acme'));                                              // the two products of the brand "Acme Dental Supplies Brand"
    }

    public function test_short_words_and_stop_words_fall_back_to_a_plain_search(): void
    {
        $this->catalogue();

        $this->assertSame(['3m-espe-cavit-g-filling'], $this->slugs('3M'));                      // shorter than 3 letters: FULLTEXT cannot be used
        $this->assertSame(['3m-espe-cavit-g-filling'], $this->slugs('cavit g'));                  // one short word
        $this->assertSame([], $this->slugs('the'));                                               // a stop word does not break the query
    }

    public function test_special_characters_in_the_search_are_harmless(): void
    {
        $this->catalogue();

        foreach (['100%', 'a_b', '"quoted', '+-<>()~*@', "it's", 'back\\slash', '   '] as $term) {
            $this->getJson(self::STORE.'/products?q='.urlencode($term))->assertOk();
        }
    }

    public function test_the_admin_search_uses_the_same_rules_and_finds_variant_skus(): void
    {
        $this->catalogue();
        $acme = $this->makeCompany('Other Co');
        $this->makeProduct($acme, $this->makeCategory('Other'), ['name' => 'Gloves', 'sku' => 'GLV', 'variants' => [
            ['name' => 'Small', 'sku' => 'GLV-SMALL', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'Large', 'sku' => 'GLV-LARGE', 'mrp' => 10, 'selling_price' => 9],
        ]]);
        $this->actingAsAdmin();
        $names = fn (string $q) => collect($this->getJson(self::ADMIN.'/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Prime-Dent Hybrid Composite Kit'], $names('composite'));
        $this->assertSame(['Gloves'], $names('GLV-LA'));                  // start of a variant SKU
        $this->assertSame(['Rotary Endo File Set'], $names('RF-200'));
        $this->assertSame(['3M ESPE Cavit G Filling'], $names('3M'));
    }

    public function test_the_company_search_uses_the_name_words_the_gst_start_and_the_email_start(): void
    {
        $this->makeCompany('Acme Dental Supplies');
        $this->makeCompany('Beta Orthodontics');
        $this->actingAsAdmin();
        $names = fn (string $q) => collect($this->getJson(self::ADMIN.'/companies?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Acme Dental Supplies'], $names('dental'));      // a word of the name
        $this->assertSame(['Beta Orthodontics'], $names('ortho'));          // the start of a word
        $this->assertCount(2, $names('GST-PT'));                            // GST number starts with
        $this->assertCount(2, $names('c'));                                 // short text: plain LIKE on the name, GST and email starts
        $this->assertSame(['Beta Orthodontics'], $names(Company::where('name', 'Beta Orthodontics')->value('email')));   // email starts with
        $this->assertSame([], $names('zebra'));
    }
}

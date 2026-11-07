<?php

namespace Tests\Feature;

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
        $category = $this->makeCategory();
        $this->makeProduct($category, ['name' => 'Prime-Tech Hybrid Charger Kit', 'short_description' => 'Light braided portable', 'sku' => 'PD-100']);
        $this->makeProduct($category, ['name' => 'Rotary Audio Cable Set', 'short_description' => 'Nickel titanium cables', 'sku' => 'RF-200']);
        $this->makeProduct($category, ['name' => '3M ESPE Cable G Filling', 'short_description' => 'Temporary cable material', 'sku' => 'CV-300']);
    }

    public function test_the_store_search_finds_words_anywhere_in_the_name_and_description(): void
    {
        $this->catalogue();

        $this->assertSame(['prime-tech-hybrid-charger-kit'], $this->slugs('charger'));      // a word in the middle of the name
        $this->assertSame(['prime-tech-hybrid-charger-kit'], $this->slugs('char'));           // the start of a word
        $this->assertSame(['prime-tech-hybrid-charger-kit'], $this->slugs('hybrid charger'));   // every word must match
        $this->assertSame([], $this->slugs('hybrid audio'));
        $this->assertSame(['prime-tech-hybrid-charger-kit'], $this->slugs('tech'));           // "Prime-Tech" is two words
        $this->assertSame(['prime-tech-hybrid-charger-kit'], $this->slugs('portable'));    // short description
        $this->assertSame(['rotary-audio-cable-set'], $this->slugs('titanium'));
        $this->assertSame([], $this->slugs('zebra'));
    }

    public function test_the_store_search_also_matches_the_sku_start(): void
    {
        $this->catalogue();

        $this->assertSame(['rotary-audio-cable-set'], $this->slugs('RF-2'));
    }

    public function test_short_words_and_stop_words_fall_back_to_a_plain_search(): void
    {
        $this->catalogue();

        $this->assertSame(['3m-espe-cable-g-filling'], $this->slugs('3M'));                      // shorter than 3 letters: FULLTEXT cannot be used
        $this->assertSame(['3m-espe-cable-g-filling'], $this->slugs('cable g'));                  // one short word
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
        $this->makeProduct($this->makeCategory('Other'), ['name' => 'Mittens', 'sku' => 'GLV', 'variants' => [
            ['name' => 'Small', 'sku' => 'GLV-SMALL', 'mrp' => 10, 'selling_price' => 9],
            ['name' => 'Large', 'sku' => 'GLV-LARGE', 'mrp' => 10, 'selling_price' => 9],
        ]]);
        $this->actingAsAdmin();
        $names = fn (string $q) => collect($this->getJson(self::ADMIN.'/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Prime-Tech Hybrid Charger Kit'], $names('charger'));
        $this->assertSame(['Mittens'], $names('GLV-LA'));                  // start of a variant SKU
        $this->assertSame(['Rotary Audio Cable Set'], $names('RF-200'));
        $this->assertSame(['3M ESPE Cable G Filling'], $names('3M'));
    }
}

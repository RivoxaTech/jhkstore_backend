<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Searching orders by customer name uses the FULLTEXT index on orders.customer_name. InnoDB only indexes COMMITTED rows, so this test
 * cannot run inside the transaction the other tests use: it truncates the tables instead (and cleans up again afterwards).
 */
class OrderSearchTest extends TestCase
{
    use BuildsShop, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_search_by_customer_name_matches_word_starts(): void
    {
        $ahmed = $this->orderFor(['name' => 'Ahmed Raza Khan', 'phone' => '+1 555 010 1111']);
        $ayesha = $this->orderFor(['name' => 'Ayesha Bibi', 'phone' => '+1 555 010 2222']);
        $this->asAdmin();
        $find = fn (string $q) => collect($this->getJson(self::ADMIN.'/orders?q='.urlencode($q))->assertOk()->json('data'))->pluck('order_number')->sort()->values()->all();

        $this->assertSame([$ahmed->order_number], $find('ahmed'));
        $this->assertSame([$ahmed->order_number], $find('ahm kha'));          // every typed word is a word start
        $this->assertSame([$ahmed->order_number], $find('raza'));
        $this->assertSame([$ayesha->order_number], $find('bibi'));
        $this->assertSame([$ayesha->order_number], $find('ay'));              // under 3 letters: LIKE fallback
        $this->assertSame([], $find('zzzz'));

        // the name search really goes through the index
        $plan = DB::select('EXPLAIN SELECT id FROM orders WHERE MATCH(orders.customer_name) AGAINST (? IN BOOLEAN MODE)', ['+ahmed*']);
        $this->assertSame('fulltext', $plan[0]->type);
    }
}

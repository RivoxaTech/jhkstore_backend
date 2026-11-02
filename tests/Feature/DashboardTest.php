<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\SaveTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    public function test_dashboard_returns_counts_and_recent_activity(): void
    {
        $this->seedBasics();
        $admin = $this->actingAsAdmin();

        app(SaveTag::class)->create(['name' => 'Alpha', 'is_active' => true], $admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_activity.0.subject_name', 'Alpha')
            ->assertJsonPath('orders.today', 0)
            ->assertJsonPath('catalog.low_stock', 0)
            ->assertJsonStructure(['recent_activity' => [['id', 'action', 'actor', 'subject_type', 'subject_name', 'created_at']]]);
    }

    public function test_the_dashboard_shows_todays_orders_revenue_pending_and_low_stock(): void
    {
        Storage::fake('public');
        $this->seedShop();
        $one = $this->orderFor([], 2);   // 2 x 160 + 300 = 620
        $two = $this->orderFor([], 1);   // 160 + 300 = 460
        $three = $this->orderFor([], 1, ['stock' => 7]);   // 460, leaves 6; threshold 5 -> not low
        $this->asAdmin();
        $this->postJson(self::ADMIN."/orders/{$two->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$three->id}/status", ['status' => 'cancelled', 'reason' => 'Test'])->assertOk();
        DB::table('product_variants')->where('id', $one->items()->value('variant_id'))->update(['stock_qty' => 4]);   // running low

        $res = $this->getJson(self::ADMIN.'/dashboard')->assertOk();

        $this->assertSame(2, $res->json('orders.today'));                 // the cancelled one does not count
        $this->assertEquals(1080, $res->json('orders.revenue_today'));    // 620 + 460
        $this->assertSame(1, $res->json('orders.pending'));
        $this->assertGreaterThanOrEqual(1, $res->json('catalog.low_stock'));
    }

    public function test_dashboard_requires_admin(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }
}

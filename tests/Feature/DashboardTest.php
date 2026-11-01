<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\SaveBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_counts_and_recent_activity(): void
    {
        $this->seedBasics();
        $admin = $this->actingAsAdmin();

        app(SaveBrand::class)->create(['name' => 'Alpha', 'is_active' => true], null, null, $admin);
        app(SaveBrand::class)->create(['name' => 'Beta', 'is_active' => false], null, null, $admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('brands.total', 2)
            ->assertJsonPath('brands.active', 1)
            ->assertJsonPath('orders.today', 0)
            ->assertJsonMissingPath('companies')
            ->assertJsonStructure(['recent_activity' => [['id', 'action', 'actor', 'subject_type', 'subject_name', 'created_at']]]);
    }

    public function test_dashboard_requires_admin(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }
}

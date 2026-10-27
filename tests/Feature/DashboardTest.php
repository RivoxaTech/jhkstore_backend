<?php

namespace Tests\Feature;

use App\Domain\Company\Actions\CreateCompany;
use App\Domain\Company\Actions\ReviewCompany;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name, string $gst, CompanySource $source, $admin = null)
    {
        return app(CreateCompany::class)->execute([
            'name' => $name,
            'registration_type_id' => CompanyRegistrationType::value('id'),
            'vendor_type_id' => VendorType::value('id'),
            'origin_country_id' => Country::value('id'),
            'email' => 'a@b.test', 'contact_number' => '9999999999', 'vat_gst_number' => $gst,
            'contact_name' => 'X', 'contact_mobile' => '9999999999', 'contact_email' => 'x@b.test',
            'address' => 'Road', 'city' => 'Delhi', 'zip_code' => '110001', 'promotion_support' => str_repeat('a', 200),
        ], [['name' => $name.' Brand']], [], $source, $admin);
    }

    public function test_dashboard_returns_counts_recent_pending_and_activity(): void
    {
        $this->seedBasics();
        $admin = $this->actingAsAdmin();

        $this->company('Pending Co', 'GST-P-1', CompanySource::PublicForm);
        $approved = $this->company('Approved Co', 'GST-A-1', CompanySource::Admin, $admin);
        $toReject = $this->company('Rejected Co', 'GST-R-1', CompanySource::PublicForm);
        app(ReviewCompany::class)->reject($toReject, $admin, 'Not suitable');

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('companies.total', 3)
            ->assertJsonPath('companies.pending', 1)
            ->assertJsonPath('companies.approved', 1)
            ->assertJsonPath('companies.rejected', 1)
            ->assertJsonPath('brands.total', 3)
            ->assertJsonPath('brands.active', 1)
            ->assertJsonCount(1, 'recent_pending')
            ->assertJsonPath('recent_pending.0.name', 'Pending Co')
            ->assertJsonStructure(['recent_activity' => [['id', 'action', 'actor', 'subject_type', 'subject_name', 'created_at']]]);

        $this->assertNotNull($approved->id);
    }

    public function test_dashboard_requires_admin(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }
}

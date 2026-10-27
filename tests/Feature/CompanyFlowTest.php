<?php

namespace Tests\Feature;

use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function payload(array $overrides = []): array
    {
        // Ids are looked up: MySQL does not reset auto-increment values after a rolled-back test transaction.
        $countryId = Country::where('code', 'IN')->value('id');

        return array_merge([
            'name' => 'Waldent Innovations Pvt Ltd',
            'registration_type_id' => CompanyRegistrationType::value('id'),
            'email' => 'info@waldent.test',
            'origin_country_id' => $countryId,
            'vendor_type_id' => VendorType::value('id'),
            'contact_number' => '+91 9876543210',
            'vat_gst_number' => '07AAAAA0000A1Z5',
            'contact_name' => 'Asha Rao',
            'contact_mobile' => '9876543210',
            'contact_email' => 'asha@waldent.test',
            'contact_whatsapp' => '9876543210',
            'address' => '12 Industrial Area',
            'landmark' => 'Near Metro',
            'city' => 'New Delhi',
            'zip_code' => '110074',
            'promotion_support' => str_repeat('We will support with banners, discounts and clinic demos. ', 5),
            'brands' => [
                ['name' => 'Waldent', 'origin_country_id' => $countryId],
                ['name' => 'Waldent AlChem', 'origin_country_id' => $countryId],
            ],
            'logo' => UploadedFile::fake()->image('company-logo.png', 300, 300),
            'catalogue' => [UploadedFile::fake()->create('catalogue.pdf', 200, 'application/pdf')],
        ], $overrides);
    }

    public function test_options_endpoint_returns_dropdown_data(): void
    {
        $this->getJson('/api/v1/store/company-registration/options')
            ->assertOk()
            ->assertJsonStructure(['registration_types', 'vendor_types', 'countries', 'rules']);
    }

    public function test_each_dropdown_has_its_own_endpoint_and_matches_the_combined_one(): void
    {
        $all = $this->getJson('/api/v1/store/company-registration/options')->assertOk()->json();

        $types = $this->getJson('/api/v1/store/lookups/registration-types')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]])->json('data');
        $vendors = $this->getJson('/api/v1/store/lookups/vendor-types')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]])->json('data');
        $countries = $this->getJson('/api/v1/store/lookups/countries')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name']]])->json('data');

        $this->assertSame($all['registration_types'], $types);
        $this->assertSame($all['vendor_types'], $vendors);
        $this->assertSame($all['countries'], $countries);
        $this->assertGreaterThanOrEqual(5, count($vendors));
        $this->assertGreaterThan(30, count($countries));
    }

    public function test_public_registration_creates_pending_company_with_inactive_brands(): void
    {
        $this->postJson('/api/v1/store/company-registrations', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $company = Company::first();
        $this->assertSame('pending', $company->status->value);
        $this->assertSame('public_form', $company->source->value);
        $this->assertCount(2, $company->brands);
        $this->assertFalse($company->brands->first()->is_active);
        $this->assertCount(1, $company->getMedia('catalogue'));
        $this->assertCount(1, $company->getMedia('logo'));

        // Pending company's brands are invisible to customers.
        $this->getJson('/api/v1/store/brands')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_registration_validation_rules(): void
    {
        $this->postJson('/api/v1/store/company-registrations', $this->payload(['promotion_support' => 'too short']))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_support');

        // The company logo is required, the catalogue is optional.
        $this->postJson('/api/v1/store/company-registrations', $this->payload(['logo' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->postJson('/api/v1/store/company-registrations', $this->payload(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')]))
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->postJson('/api/v1/store/company-registrations', $this->payload(['catalogue' => [], 'name' => 'No Catalogue Co', 'vat_gst_number' => 'GST-NOCAT-1']))
            ->assertCreated();

        $this->postJson('/api/v1/store/company-registrations', $this->payload(['brands' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('brands');

        $this->postJson('/api/v1/store/company-registrations', $this->payload([
            'catalogue' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
        ]))->assertStatus(422)->assertJsonValidationErrors('catalogue.0');
    }

    public function test_duplicate_gst_is_rejected_until_the_first_one_is_rejected(): void
    {
        $this->postJson('/api/v1/store/company-registrations', $this->payload())->assertCreated();

        $this->postJson('/api/v1/store/company-registrations', $this->payload([
            'name' => 'Other Co', 'vat_gst_number' => '07aaaaa0000a1z5',
            'brands' => [['name' => 'Other Brand']],
            'catalogue' => [UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')],
        ]))->assertStatus(422)->assertJsonValidationErrors('vat_gst_number');

        $admin = $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/companies/'.Company::first()->id.'/reject', ['reason' => 'Documents unclear'])->assertOk();

        $this->postJson('/api/v1/store/company-registrations', $this->payload([
            'name' => 'Other Co',
            'brands' => [['name' => 'Other Brand']],
            'catalogue' => [UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')],
        ]))->assertCreated();
    }

    public function test_admin_approval_makes_brands_visible_to_customers(): void
    {
        $this->postJson('/api/v1/store/company-registrations', $this->payload())->assertCreated();
        $company = Company::first();

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/companies?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Waldent Innovations Pvt Ltd')
            ->assertJsonPath('counts.pending', 1);

        $this->postJson("/api/v1/admin/companies/{$company->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue(Brand::where('company_id', $company->id)->where('is_active', true)->count() === 2);

        $this->getJson('/api/v1/store/brands')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.company');

        $this->getJson('/api/v1/store/brands/waldent-alchem')->assertOk()->assertJsonPath('data.name', 'Waldent AlChem');

        // Suspending hides them again.
        $this->postJson("/api/v1/admin/companies/{$company->id}/suspend")->assertOk();
        $this->getJson('/api/v1/store/brands')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_create_company_which_is_auto_approved(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.source', 'admin');

        $this->getJson('/api/v1/store/brands')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_company_detail_shows_brands_and_private_catalogue(): void
    {
        $this->postJson('/api/v1/store/company-registrations', $this->payload())->assertCreated();
        $company = Company::first();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.brands')
            ->assertJsonCount(1, 'data.catalogue')
            ->assertJsonStructure(['data' => ['catalogue' => [['id', 'name', 'download_url']]]]);

        $this->getJson("/api/v1/admin/companies/{$company->id}/brands")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_add_brand_with_logo_and_it_exposes_an_absolute_image_url(): void
    {
        $admin = $this->actingAsAdmin();
        $company = $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->json('data');

        $response = $this->post("/api/v1/admin/companies/{$company['id']}/brands", [
            'name' => 'Waldent Instruments',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertStringStartsWith('http', $response->json('data.logo_url'));
        $this->assertTrue($response->json('data.is_active'));

        // Same brand name in the same company is rejected.
        $this->postJson("/api/v1/admin/companies/{$company['id']}/brands", ['name' => 'Waldent Instruments'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_brand_without_own_logo_shows_the_company_logo_and_own_logo_wins(): void
    {
        $this->actingAsAdmin();
        $company = $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->assertCreated()->json('data');
        $this->assertStringStartsWith('http', $company['logo_url']);

        // Both brands of the new company have no logo of their own -> they show the company logo.
        $brands = $this->getJson("/api/v1/store/brands")->assertOk()->json('data');
        $this->assertCount(2, $brands);
        foreach ($brands as $brand) {
            $this->assertStringStartsWith('http', $brand['logo_url']);
            $this->assertStringStartsWith('http', $brand['logo_thumb_url']);
        }

        $adminBrands = $this->getJson("/api/v1/admin/companies/{$company['id']}/brands")->assertOk()->json('data');
        $this->assertSame('company', $adminBrands[0]['logo_source']);
        $this->assertFalse($adminBrands[0]['has_own_logo']);
        $this->assertSame($company['logo_url'], $adminBrands[0]['logo_url']);

        // Give one brand its own logo: it is used instead of the company logo.
        $brandId = $adminBrands[0]['id'];
        $own = $this->post("/api/v1/admin/brands/{$brandId}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('brand.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('brand', $own->json('data.logo_source'));
        $this->assertNotSame($company['logo_url'], $own->json('data.logo_url'));

        // Removing the brand's logo falls back to the company logo again.
        $this->post("/api/v1/admin/brands/{$brandId}", ['_method' => 'PUT', 'remove_logo' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.logo_source', 'company')
            ->assertJsonPath('data.logo_url', $company['logo_url']);
    }

    public function test_admin_company_list_is_compact_and_has_the_columns_of_the_table(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->assertCreated();

        $this->getJson('/api/v1/admin/companies?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'name', 'status', 'source', 'logo_thumb_url', 'registration_type', 'vendor_type', 'origin_country',
                'email', 'vat_gst_number', 'contact' => ['name', 'mobile'], 'city', 'brands_count', 'brand_names',
                'catalogue_count', 'submitted_at',
            ]], 'counts']);
    }

    public function test_admin_can_replace_the_company_logo(): void
    {
        $this->actingAsAdmin();
        $company = $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->json('data');

        $res = $this->post("/api/v1/admin/companies/{$company['id']}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('new-logo.png', 120, 120),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotSame($company['logo_url'], $res->json('data.logo_url'));
        $this->assertCount(1, Company::find($company['id'])->getMedia('logo')); // single file: the old one is replaced
    }

    public function test_a_company_must_keep_at_least_one_brand(): void
    {
        $this->actingAsAdmin();

        // The form refuses a registration without any brand.
        $this->postJson('/api/v1/store/company-registrations', $this->payload(['brands' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('brands');
        $this->postJson('/api/v1/store/company-registrations', $this->payload(['brands' => [['name' => '   ']]]))
            ->assertStatus(422);

        // Two brands: one can be deleted, the last one cannot.
        $company = $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->assertCreated()->json('data');
        $brands = $this->getJson("/api/v1/admin/companies/{$company['id']}/brands")->json('data');
        $this->assertCount(2, $brands);

        $this->deleteJson('/api/v1/admin/brands/'.$brands[0]['id'])->assertOk();
        $this->deleteJson('/api/v1/admin/brands/'.$brands[1]['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A company needs at least one brand. Add another brand first, or delete the company.');

        $this->getJson("/api/v1/admin/companies/{$company['id']}/brands")->assertJsonCount(1, 'data');
    }

    public function test_company_with_products_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $company = $this->postJson('/api/v1/admin/companies', $this->payload(['catalogue' => []]))->json('data');

        $this->deleteJson("/api/v1/admin/companies/{$company['id']}")->assertOk();
        $this->assertSoftDeleted('companies', ['id' => $company['id']]);
    }
}

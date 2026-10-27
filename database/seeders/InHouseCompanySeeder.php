<?php

namespace Database\Seeders;

use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use Illuminate\Database\Seeder;

/** Owner company for products that have no external supplier (e.g. imported frontend demo products). */
class InHouseCompanySeeder extends Seeder
{
    public function run(): void
    {
        if (Company::where('is_in_house', true)->exists()) {
            return;
        }

        $company = new Company([
            'name' => 'Dentalkart (In-house)',
            'registration_type_id' => CompanyRegistrationType::where('name', 'Private Limited Company')->value('id'),
            'vendor_type_id' => VendorType::where('name', 'Distributor')->value('id'),
            'origin_country_id' => Country::where('code', 'IN')->value('id'),
            'email' => 'support@dentalkart.com',
            'contact_number' => '+91-728-9999-456',
            'vat_gst_number' => 'IN-HOUSE-000',
            'contact_name' => 'Dentalkart Support',
            'contact_mobile' => '+91-728-9999-456',
            'contact_email' => 'support@dentalkart.com',
            'address' => 'Registered office, New Delhi',
            'city' => 'New Delhi',
            'zip_code' => '110074',
            'promotion_support' => 'In-house company used for products supplied directly by Dentalkart.',
        ]);
        $company->slug = Company::uniqueSlug($company->name);
        $company->source = CompanySource::Admin;
        $company->status = CompanyStatus::Approved;
        $company->is_in_house = true;
        $company->submitted_at = now();
        $company->reviewed_at = now();
        $company->save();
    }
}

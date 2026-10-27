<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company with its brands, logo and (optional) catalogue files.
 * Public form submissions start as "pending"; companies created by an admin are approved immediately.
 */
class CreateCompany
{
    /**
     * @param  array<string,mixed>  $data  company columns
     * @param  array<int,array{name:string,origin_country_id?:int|null}>  $brands
     * @param  array<int,UploadedFile>  $catalogue
     */
    public function execute(
        array $data,
        array $brands,
        array $catalogue,
        CompanySource $source,
        ?Admin $admin = null,
        ?UploadedFile $logo = null,
    ): Company {
        return DB::transaction(function () use ($data, $brands, $catalogue, $source, $admin, $logo) {
            $approved = $source === CompanySource::Admin;

            $company = new Company($data);
            $company->slug = Company::uniqueSlug($data['name']);
            $company->vat_gst_number = strtoupper(trim($data['vat_gst_number']));
            $company->source = $source;
            $company->status = $approved ? CompanyStatus::Approved : CompanyStatus::Pending;
            $company->submitted_at = now();
            if ($approved) {
                $company->reviewed_by = $admin?->id;
                $company->reviewed_at = now();
            }
            $company->save();

            foreach ($brands as $index => $brand) {
                $model = new Brand([
                    'company_id' => $company->id,
                    'name' => $brand['name'],
                    'origin_country_id' => $brand['origin_country_id'] ?? null,
                    'is_active' => $approved,
                    'sort_order' => $index,
                ]);
                $model->slug = Brand::uniqueSlug($brand['name']);
                $model->save();
            }

            if ($logo) {
                $company->addMedia($logo)->toMediaCollection('logo');
            }
            foreach ($catalogue as $file) {
                $company->addMedia($file)->toMediaCollection('catalogue');
            }

            Audit::log($admin, $approved ? 'company.created' : 'company.registered', $company);

            return $company->load(['brands', 'registrationType', 'vendorType', 'originCountry']);
        });
    }
}

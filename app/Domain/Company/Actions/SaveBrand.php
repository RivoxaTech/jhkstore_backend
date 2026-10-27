<?php

namespace App\Domain\Company\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Company\Enums\CompanyStatus;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/** Creates or updates a brand of a company (admin side), including logo and banner uploads. */
class SaveBrand
{
    public function create(Company $company, array $data, ?UploadedFile $logo, ?UploadedFile $banner, Admin $admin): Brand
    {
        unset($data['remove_logo']);
        $brand = new Brand($data);
        $brand->company_id = $company->id;
        $brand->slug = Brand::uniqueSlug($data['name']);
        // A brand can only be live when its company is approved.
        $brand->is_active = ($data['is_active'] ?? true) && $company->status === CompanyStatus::Approved;
        $brand->save();

        $this->attachImages($brand, $logo, $banner);
        Audit::log($admin, 'brand.created', $brand);

        return $brand->fresh(['company', 'originCountry']);
    }

    public function update(Brand $brand, array $data, ?UploadedFile $logo, ?UploadedFile $banner, Admin $admin): Brand
    {
        if (isset($data['name']) && $data['name'] !== $brand->name) {
            $brand->slug = Brand::uniqueSlug($data['name'], $brand->id);
        }
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $data['is_active'] && $brand->company->status === CompanyStatus::Approved;
        }
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['remove_logo']);
        $brand->fill($data)->save();

        if ($removeLogo && ! $logo) {
            $brand->clearMediaCollection('logo'); // falls back to the company logo
        }
        $this->attachImages($brand, $logo, $banner);
        Audit::log($admin, 'brand.updated', $brand, $brand->getChanges());

        return $brand->fresh(['company', 'originCountry']);
    }

    private function attachImages(Brand $brand, ?UploadedFile $logo, ?UploadedFile $banner): void
    {
        if ($logo) {
            $brand->addMedia($logo)->toMediaCollection('logo');
        }
        if ($banner) {
            $brand->addMedia($banner)->toMediaCollection('banner');
        }
    }
}

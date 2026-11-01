<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Brand;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/** Creates or updates a brand (admin side), including logo and banner uploads. */
class SaveBrand
{
    public function create(array $data, ?UploadedFile $logo, ?UploadedFile $banner, Admin $admin): Brand
    {
        unset($data['remove_logo']);
        $brand = new Brand($data);
        $brand->slug = Brand::uniqueSlug($data['name']);
        $brand->is_active = $data['is_active'] ?? true;
        $brand->save();

        $this->attachImages($brand, $logo, $banner);
        Audit::log($admin, 'brand.created', $brand);

        return $brand->fresh();
    }

    public function update(Brand $brand, array $data, ?UploadedFile $logo, ?UploadedFile $banner, Admin $admin): Brand
    {
        if (isset($data['name']) && $data['name'] !== $brand->name) {
            $brand->slug = Brand::uniqueSlug($data['name'], $brand->id);
        }
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['remove_logo']);
        $brand->fill($data)->save();

        if ($removeLogo && ! $logo) {
            $brand->clearMediaCollection('logo');
        }
        $this->attachImages($brand, $logo, $banner);
        Audit::log($admin, 'brand.updated', $brand, $brand->getChanges());

        return $brand->fresh();
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

<?php

namespace App\Domain\Content\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Content\Models\Banner;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Creates, updates, deletes and reorders banners (admin side), including the desktop and mobile pictures. */
class SaveBanner
{
    public function create(array $data, UploadedFile $desktop, ?UploadedFile $mobile, Admin $admin): Banner
    {
        return DB::transaction(function () use ($data, $desktop, $mobile, $admin) {
            $banner = new Banner($data);
            // new banners go last in their placement unless a position is given
            $banner->sort_order = $data['sort_order'] ?? ((int) Banner::query()->where('placement', $data['placement'])->max('sort_order') + 1);
            $banner->is_active = $data['is_active'] ?? true;
            $banner->save();

            $banner->addMedia($desktop)->toMediaCollection('desktop');
            if ($mobile) {
                $banner->addMedia($mobile)->toMediaCollection('mobile');
            }
            Audit::log($admin, 'banner.created', $banner);

            return $banner;
        });
    }

    /** `remove_mobile_image` in $data drops the mobile picture (the desktop picture can only be replaced). */
    public function update(Banner $banner, array $data, ?UploadedFile $desktop, ?UploadedFile $mobile, Admin $admin): Banner
    {
        return DB::transaction(function () use ($banner, $data, $desktop, $mobile, $admin) {
            $removeMobile = (bool) ($data['remove_mobile_image'] ?? false);
            unset($data['remove_mobile_image']);

            $banner->fill($data)->save();
            if ($desktop) {
                $banner->addMedia($desktop)->toMediaCollection('desktop');
            }
            if ($mobile) {
                $banner->addMedia($mobile)->toMediaCollection('mobile');
            } elseif ($removeMobile) {
                $banner->clearMediaCollection('mobile');
            }
            Audit::log($admin, 'banner.updated', $banner, $banner->getChanges());

            return $banner;
        });
    }

    public function delete(Banner $banner, Admin $admin): void
    {
        $banner->delete();   // medialibrary removes the files
        Audit::log($admin, 'banner.deleted', $banner);
    }

    /**
     * Gives the banners the positions 1..n in the order of $ids (all banners of one placement, or several).
     * Banners not in the list keep their number.
     *
     * @param  list<int>  $ids
     */
    public function reorder(array $ids, Admin $admin): void
    {
        DB::transaction(function () use ($ids, $admin) {
            foreach (array_values($ids) as $position => $id) {
                Banner::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
            Audit::log($admin, 'banner.reordered', null, ['ids' => array_values($ids)]);
        });
    }
}

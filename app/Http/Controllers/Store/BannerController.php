<?php

namespace App\Http\Controllers\Store;

use App\Domain\Content\Models\Banner;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreBannerResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BannerController extends Controller
{
    /**
     * Banners to show right now: active, inside their start / end dates, in the order set in the admin panel.
     * `?placement=home_hero` (home_hero, home_secondary, promo_strip) returns one spot; without it every live banner is returned (each has its `placement`).
     * A spot can hold one banner or several - show a single banner or a slider as you like. `data` is empty when nothing is live.
     */
    public function index(Request $request)
    {
        $request->validate(['placement' => ['nullable', 'string', Rule::in(array_keys(Banner::PLACEMENTS))]]);

        return StoreBannerResource::collection(
            Banner::query()->live()->with('media')
                ->when($request->query('placement'), fn ($q, $p) => $q->where('placement', $p))
                ->orderBy('placement')->ordered()->get()
                ->filter(fn (Banner $b) => $b->hasMedia('desktop'))->values()   // a banner without a picture is never shown
        );
    }
}

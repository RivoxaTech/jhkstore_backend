<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Content\Actions\SaveBanner;
use App\Domain\Content\Models\Banner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBannerRequest;
use App\Http\Resources\BannerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BannerController extends Controller
{
    /** The placements to choose from (`key` => label). */
    public function options(): JsonResponse
    {
        return response()->json([
            'placements' => collect(Banner::PLACEMENTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /** Every banner, grouped by placement and in display order (?placement= narrows it; ?status=live|scheduled|expired|inactive). Not paginated: a store has a handful. */
    public function index(Request $request)
    {
        $request->validate([
            'placement' => ['nullable', Rule::in(array_keys(Banner::PLACEMENTS))],
            'status' => ['nullable', Rule::in(['live', 'scheduled', 'expired', 'inactive'])],
        ]);

        $banners = Banner::query()->with('media')
            ->when($request->query('placement'), fn ($q, $p) => $q->where('placement', $p))
            ->orderBy('placement')->ordered()->get()
            ->when($request->query('status'), fn ($c, $s) => $c->filter(fn (Banner $b) => $b->status() === $s)->values());

        return BannerResource::collection($banners);
    }

    public function show(Banner $banner): BannerResource
    {
        return new BannerResource($banner->load('media'));
    }

    public function store(SaveBannerRequest $request, SaveBanner $save): JsonResponse
    {
        $banner = $save->create($request->bannerData(), $request->file('desktop_image'), $request->file('mobile_image'), $request->user());

        return (new BannerResource($banner->load('media')))->response()->setStatusCode(201);
    }

    /** Send only the fields that change. Multipart (with a picture) clients send POST + `_method=PUT`. */
    public function update(SaveBannerRequest $request, Banner $banner, SaveBanner $save): BannerResource
    {
        $save->update($banner, $request->bannerData(), $request->file('desktop_image'), $request->file('mobile_image'), $request->user());

        return new BannerResource($banner->fresh()->load('media'));
    }

    public function destroy(Request $request, Banner $banner, SaveBanner $save): JsonResponse
    {
        $save->delete($banner, $request->user());

        return response()->json(['message' => 'Banner deleted.']);
    }

    /** `ids` = the banners in the new order (position 1..n). Send the banners of one placement. */
    public function reorder(Request $request, SaveBanner $save): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', Rule::exists('banners', 'id')],
        ]);
        $save->reorder($data['ids'], $request->user());

        return response()->json(['message' => 'Order saved.']);
    }
}

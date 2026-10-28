<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Support\Models\SiteSetting;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function showDelivery(): JsonResponse
    {
        return response()->json([
            'delivery_fee' => (float) SiteSetting::read('delivery_fee', 300),
            'free_delivery_min_cart' => (float) SiteSetting::read('free_delivery_min_cart', 8500),
            'channels' => DB::table('sales_channels')->get(['id', 'code', 'name', 'is_active', 'delivery_days']),
        ]);
    }

    /** Update the flat delivery charge, the free-delivery cart value and delivery days per channel. */
    public function updateDelivery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'free_delivery_min_cart' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'channels' => ['sometimes', 'array'],
            'channels.*.code' => ['required', 'string', 'exists:sales_channels,code'],
            'channels.*.delivery_days' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        SiteSetting::write('shipping', 'delivery_fee', (float) $data['delivery_fee']);
        SiteSetting::write('shipping', 'free_delivery_min_cart', (float) $data['free_delivery_min_cart']);

        foreach ($data['channels'] ?? [] as $channel) {
            DB::table('sales_channels')->where('code', $channel['code'])->update(['delivery_days' => $channel['delivery_days'], 'updated_at' => now()]);
        }

        Audit::log($request->user(), 'settings.delivery_updated', null, $data);

        return $this->showDelivery();
    }
}

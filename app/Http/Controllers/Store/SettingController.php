<?php

namespace App\Http\Controllers\Store;

use App\Domain\Support\Models\SiteSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /** Delivery charge, free-delivery limit and delivery time per channel (all editable by admin). */
    public function delivery(): JsonResponse
    {
        return response()->json([
            'currency' => SiteSetting::read('currency_code', 'PKR'),
            'currency_symbol' => SiteSetting::read('currency_symbol', 'Rs'),
            'delivery_fee' => (float) SiteSetting::read('delivery_fee', 300),
            'free_delivery_min_cart' => (float) SiteSetting::read('free_delivery_min_cart', 8500),
            'channels' => DB::table('sales_channels')->where('is_active', true)->get(['code', 'name', 'delivery_days', 'requires_login']),
        ]);
    }
}

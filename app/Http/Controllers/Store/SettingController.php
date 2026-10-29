<?php

namespace App\Http\Controllers\Store;

use App\Domain\Support\CommerceSettings;
use App\Domain\Support\Models\SiteSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /** Delivery charge, free-delivery limit and delivery time per channel (all editable by admin). */
    public function delivery(CommerceSettings $settings): JsonResponse
    {
        return response()->json([
            'currency' => SiteSetting::read('currency_code', 'PKR'),
            'currency_symbol' => SiteSetting::read('currency_symbol', 'Rs'),
            'delivery_fee' => $settings->get('delivery_fee'),
            'free_delivery_min_cart' => $settings->get('free_delivery_min_cart'),   // 0 = no free delivery
            'coin_value' => $settings->get('coin_value'),                           // money per coin
            'min_order_amount' => $settings->get('min_order_amount'),               // 0 = no minimum
            'cod_max_amount' => $settings->get('cod_max_amount'),                   // 0 = no limit
            'tax_note' => 'Prices are inclusive of all taxes',
            'channels' => DB::table('sales_channels')->where('is_active', true)->get(['code', 'name', 'delivery_days', 'requires_login']),
        ]);
    }
}

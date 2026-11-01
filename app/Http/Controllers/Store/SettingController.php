<?php

namespace App\Http\Controllers\Store;

use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /** Public store configuration: profile, currency, tax, delivery and which optional features are on. */
    public function show(Settings $settings): JsonResponse
    {
        $rewards = $settings->rewardsEnabled();

        return response()->json([
            'store' => [
                'name' => $settings->get('store_name'),
                'tagline' => $settings->get('store_tagline'),
                'logo_url' => $settings->logoUrl(),
                'email' => $settings->get('store_email'),
                'phone' => $settings->get('store_phone'),
                'address' => $settings->get('store_address'),
                'country' => $settings->get('store_country'),
            ],
            'currency' => [
                'code' => $settings->currencyCode(),
                'symbol' => $settings->currencySymbol(),
                'decimals' => $settings->decimals(),
            ],
            'tax' => [
                'rate' => $settings->get('tax_rate'),
                'inclusive' => (bool) $settings->get('tax_inclusive'),
                'label' => $settings->get('tax_label'),
            ],
            'delivery' => [
                'fee' => $settings->get('delivery_fee'),
                'free_delivery_min_cart' => $settings->get('free_delivery_min_cart'),   // 0 = never free
                'days' => $settings->get('delivery_days'),
            ],
            'orders' => [
                'min_order_amount' => $settings->get('min_order_amount'),               // 0 = no minimum
                'cod_max_amount' => $settings->get('cod_max_amount'),                   // 0 = no limit
            ],
            'features' => [
                'rewards' => $rewards,
            ],
            // only present when reward points are switched on
            'rewards' => $rewards ? [
                'earn_rate' => $settings->get('reward_earn_rate'),
                'redeem_rate' => $settings->get('reward_redeem_rate'),
            ] : null,
        ]);
    }
}

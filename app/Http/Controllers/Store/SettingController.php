<?php

namespace App\Http\Controllers\Store;

use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /** Public store configuration: profile and branding, footer and social links, SEO defaults, currency, tax, delivery and order rules. */
    public function show(Settings $settings): JsonResponse
    {
        return response()->json([
            'store' => [
                'name' => (string) $settings->get('store_name'),
                'tagline' => (string) $settings->get('store_tagline'),
                'logo_url' => $settings->logoUrl(),
                'favicon_url' => $settings->fileUrl('store_favicon'),
                'email' => (string) $settings->get('store_email'),
                'phone' => (string) $settings->get('store_phone'),
                'address' => (string) $settings->get('store_address'),
                'country' => (string) $settings->get('store_country'),
            ],
            'social_links' => $settings->publicLinks(),          // active links only, in the order set in the admin panel
            'footer' => [
                'text' => (string) $settings->get('footer_text'),
                'copyright' => (string) $settings->get('footer_copyright'),
                'support_hours' => (string) $settings->get('support_hours'),
                'whatsapp_number' => (string) $settings->get('whatsapp_number'),
                'whatsapp_url' => ($digits = preg_replace('/\D+/', '', (string) $settings->get('whatsapp_number'))) !== '' ? 'https://wa.me/'.$digits : null,
            ],
            'seo' => [
                'meta_title' => (string) $settings->get('seo_meta_title'),
                'meta_description' => (string) $settings->get('seo_meta_description'),
                'share_image_url' => $settings->fileUrl('seo_share_image'),
            ],
            'currency' => [
                'code' => $settings->currencyCode(),
                'symbol' => $settings->currencySymbol(),
                'decimals' => $settings->decimals(),
            ],
            'tax' => [
                'rate' => (float) $settings->get('tax_rate'),
                'inclusive' => (bool) $settings->get('tax_inclusive'),
                'label' => (string) $settings->get('tax_label'),
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
        ]);
    }
}

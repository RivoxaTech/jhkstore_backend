<?php

namespace App\Http\Controllers\Store;

use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Public store configuration: profile and branding, open / closed, footer, social links and the agency credit, SEO defaults, currency, tax,
     * delivery, payment methods, order rules and the theme (colours, fonts, corners) the storefront paints itself with.
     */
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
                'open' => (bool) $settings->get('store_open'),                          // false: browsing works, POST /orders answers 422 store_closed
                'closed_message' => (string) $settings->get('store_closed_message'),
            ],
            'checkout' => [
                'notice' => (string) $settings->get('checkout_notice'),
                'eta_text' => (string) $settings->get('delivery_eta_text'),             // used when no delivery zone has an estimate of its own
            ],
            'social_links' => $settings->publicLinks(),          // active links only, in the order set in the admin panel
            'footer' => [
                'text' => (string) $settings->get('footer_text'),
                'copyright' => (string) $settings->get('footer_copyright'),
                'support_hours' => (string) $settings->get('support_hours'),
                'whatsapp_number' => (string) $settings->get('whatsapp_number'),
                'whatsapp_url' => ($digits = preg_replace('/\D+/', '', (string) $settings->get('whatsapp_number'))) !== '' ? 'https://wa.me/'.$digits : null,
                'agency' => ['name' => (string) $settings->get('agency_name'), 'url' => (string) $settings->get('agency_url'), 'logo_url' => $settings->fileUrl('agency_logo')],
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
                'method' => $settings->deliveryMethod(),                               // flat | free | free_above | zones | owner_confirms
                'fee' => $settings->get('delivery_fee'),
                'free_delivery_min_cart' => $settings->get('free_delivery_min_cart'),   // 0 = never free
                'days' => $settings->get('delivery_days'),
            ],
            'orders' => [
                'min_order_amount' => $settings->get('min_order_amount'),               // 0 = no minimum
                'cod_max_amount' => $settings->get('cod_max_amount'),                   // 0 = no limit
            ],
            'payment' => [
                'cod' => ['enabled' => (bool) $settings->get('pay_cod_enabled')],
                'bank_transfer' => [
                    'enabled' => (bool) $settings->get('pay_bank_enabled'),
                    'proof_required' => $settings->bankProofRequired(),                  // the screenshot must be uploaded at checkout
                    'instructions' => (string) $settings->get('pay_bank_instructions'),
                    'accounts' => $settings->publicAccounts(),                            // active accounts, in order
                ],
                'card' => ['visible' => (bool) $settings->get('pay_card_visible'), 'coming_soon' => true],   // only a label: it can never be chosen
            ],
            'theme' => [
                'primary' => (string) $settings->get('theme_primary'),
                'primary_contrast' => (string) $settings->get('theme_primary_contrast'),
                'accent' => (string) $settings->get('theme_accent'),
                'bg' => (string) $settings->get('theme_bg'),
                'surface' => (string) $settings->get('theme_surface'),
                'text' => (string) $settings->get('theme_text'),
                'muted' => (string) $settings->get('theme_muted'),
                'border' => (string) $settings->get('theme_border'),
                'font_heading' => (string) $settings->get('theme_font_heading'),
                'font_body' => (string) $settings->get('theme_font_body'),
                'radius' => (string) $settings->get('theme_radius'),
                'shadow' => (string) $settings->get('theme_shadow'),
            ],
        ]);
    }
}

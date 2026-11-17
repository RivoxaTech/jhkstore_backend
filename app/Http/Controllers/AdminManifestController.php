<?php

namespace App\Http\Controllers;

use App\Domain\Support\Settings;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest of the admin panel (GET /admin/manifest.webmanifest): what makes the panel installable as an app on a phone or a computer.
 * Made here, not a static file, so the name is the store's name from Settings (one build serves every client). The icons are the files of
 * admin-frontend/public (replace them per client).
 */
class AdminManifestController extends Controller
{
    public function __invoke(Settings $settings): JsonResponse
    {
        $store = trim((string) $settings->get('store_name')) ?: 'Store';
        $name = $store.' Admin';
        $icon = fn (string $size, string $purpose) => ['src' => "/admin-assets/web-app-manifest-{$size}x{$size}.png", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => $purpose];

        return response()->json([
            'id' => '/admin/',
            'name' => $name,
            'short_name' => mb_strlen($name) <= 16 ? $name : 'Admin',
            'description' => "Orders, products and customers of {$store}.",
            'start_url' => '/admin/',
            'scope' => '/admin/',
            'display' => 'standalone',
            'orientation' => 'any',
            'lang' => 'en',
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
            'icons' => [$icon('192', 'any'), $icon('512', 'any'), $icon('192', 'maskable'), $icon('512', 'maskable')],
            'shortcuts' => [
                ['name' => 'Orders', 'url' => '/admin/orders', 'icons' => [['src' => '/admin-assets/web-app-manifest-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']]],
                ['name' => 'Wholesale', 'url' => '/admin/wholesale', 'icons' => [['src' => '/admin-assets/web-app-manifest-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}

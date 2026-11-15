<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The admin panel is an installable app: a manifest with the store's name, and the files the install needs. */
class AdminPwaTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedShop();
    }

    public function test_the_manifest_is_public_valid_and_carries_the_store_name(): void
    {
        $this->setSetting('store_name', 'Acme Toys');

        $res = $this->get('/admin/manifest.webmanifest')->assertOk();
        $this->assertStringContainsString('application/manifest+json', (string) $res->headers->get('Content-Type'));
        $m = $res->json();

        $this->assertSame('Acme Toys Admin', $m['name']);
        $this->assertSame('/admin/', $m['start_url']);
        $this->assertSame('/admin/', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $sizes = collect($m['icons'])->pluck('sizes')->unique()->all();
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertStringNotContainsString('JHK', json_encode($m));   // no client name in the code: it comes from the settings
    }

    public function test_a_long_store_name_gets_a_short_name_for_the_home_screen(): void
    {
        $this->setSetting('store_name', 'The Very Long Named Toy Emporium');

        $m = $this->get('/admin/manifest.webmanifest')->json();

        $this->assertSame('The Very Long Named Toy Emporium Admin', $m['name']);
        $this->assertSame('Admin', $m['short_name']);
    }

    public function test_the_icons_and_the_offline_page_exist(): void
    {
        foreach (['admin-sw.js', 'admin-offline.html', 'favicon.ico'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        foreach (['web-app-manifest-192x192.png', 'web-app-manifest-512x512.png', 'apple-touch-icon.png', 'favicon-96x96.png', 'favicon.svg'] as $file) {
            $this->assertFileExists(base_path('../admin-frontend/public/'.$file));
        }
    }
}

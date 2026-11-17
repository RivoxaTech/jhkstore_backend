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

    public function test_the_help_videos_are_served_with_byte_ranges_so_that_a_step_can_jump(): void
    {
        $dir = public_path('admin-assets/help');
        $made = ! is_dir($dir);
        if ($made) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.'/zz-test.mp4';
        file_put_contents($file, str_repeat('0123456789', 100));

        try {
            $whole = $this->get('/admin/help-media/zz-test.mp4')->assertOk();
            $this->assertSame('video/mp4', $whole->headers->get('Content-Type'));
            $this->assertSame('1000', (string) $whole->headers->get('Content-Length'));

            $part = $this->get('/admin/help-media/zz-test.mp4', ['Range' => 'bytes=100-199'])->assertStatus(206);
            $this->assertSame('bytes 100-199/1000', $part->headers->get('Content-Range'));
            $this->assertSame('100', (string) $part->headers->get('Content-Length'));
        } finally {
            @unlink($file);
            if ($made) {
                @rmdir($dir);
            }
        }

        $this->get('/admin/help-media/missing.mp4')->assertNotFound();
        // only plain names of videos and pictures: anything else is not this route (the panel's shell answers, never a file of the server)
        foreach (['..%2F..%2F.env', 'notes.txt', '..%2Fadmin-sw.js'] as $odd) {
            $res = $this->get('/admin/help-media/'.$odd);
            $this->assertNotSame('video/mp4', $res->headers->get('Content-Type'));
            $this->assertStringNotContainsString('APP_KEY', (string) $res->getContent());
        }
    }

    public function test_the_icons_and_the_offline_page_exist(): void
    {
        foreach (['admin-sw.js', 'admin-offline.html', 'favicon.ico'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        // the icons belong to the admin panel repository: checked when it is next to this one (the usual layout), skipped in a backend-only checkout
        if (is_dir(base_path('../admin-frontend/public'))) {
            foreach (['web-app-manifest-192x192.png', 'web-app-manifest-512x512.png', 'apple-touch-icon.png', 'favicon-96x96.png', 'favicon.svg'] as $file) {
                $this->assertFileExists(base_path('../admin-frontend/public/'.$file));
            }
        }
    }
}

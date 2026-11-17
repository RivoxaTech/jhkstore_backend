<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /admin/help-media/{file}: the videos (and pictures) of the Help screen, from public/admin-assets/help. A video has to be seekable (the steps jump to
 * a time), which needs HTTP byte ranges; the built-in server of `php artisan serve` cannot do that for a plain file, this route can (Laravel answers a
 * Range request with 206), so the Help screen works the same on a developer's computer and on the hosting.
 */
class AdminHelpMediaController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = public_path('admin-assets/help/'.$file);
        abort_unless(preg_match('/^[a-z0-9-]+\.(mp4|jpg)$/', $file) === 1 && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

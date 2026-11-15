<?php

use App\Http\Controllers\AdminHelpMediaController;
use App\Http\Controllers\AdminManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The app manifest of the admin panel (installable app); a real route, so the catch-all below does not answer it with the HTML shell.
Route::get('/admin/manifest.webmanifest', AdminManifestController::class);

// The Help videos, with byte ranges so that a step can jump to its time (the built-in server of `php artisan serve` cannot seek in a plain file).
Route::get('/admin/help-media/{file}', AdminHelpMediaController::class)->where('file', '[a-z0-9-]+\.(mp4|jpg)');

// Admin panel (Vue single-page app built from /admin-frontend into backend/public/admin-assets).
// Every /admin/... URL gets the app shell; its JS/CSS are static files under /admin-assets.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin-assets/index.html');
    abort_unless(is_file($index), 404, 'Admin panel is not built yet. Run: cd ../admin-frontend && npm install && npm run build');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('path', '.*');

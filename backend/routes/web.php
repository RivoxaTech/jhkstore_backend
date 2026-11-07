<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin panel (Vue single-page app built from /admin-frontend into backend/public/admin-assets).
// Every /admin/... URL gets the app shell; its JS/CSS are static files under /admin-assets.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin-assets/index.html');
    abort_unless(is_file($index), 404, 'Admin panel is not built yet. Run: cd ../admin-frontend && npm install && npm run build');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('path', '.*');

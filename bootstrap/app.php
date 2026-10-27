<?php

use App\Http\Middleware\EnsureActor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('api/v1/store')->name('store.')
                ->group(base_path('routes/api_store.php'));
            Route::middleware('api')->prefix('api/v1/admin')->name('admin.')
                ->group(base_path('routes/api_admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['actor' => EnsureActor::class]);

        // Requests from the admin panel origin (SANCTUM_STATEFUL_DOMAINS) get sessions + CSRF protection.
        // Everything else (customer site, Postman) stays stateless and uses bearer tokens.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

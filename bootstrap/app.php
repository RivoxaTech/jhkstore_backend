<?php

use App\Http\Middleware\EnsureActor;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\RejectHoneypot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        $middleware->alias(['actor' => EnsureActor::class, 'admin.can' => EnsureAdminPermission::class, 'honeypot' => RejectHoneypot::class]);
        // Who you are and what you may do are checked BEFORE route models are looked up: a missing permission is 403 (never a 404 that reveals what exists).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureActor::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAdminPermission::class);

        // Requests from the admin panel origin (SANCTUM_STATEFUL_DOMAINS) get sessions + CSRF protection.
        // Everything else (the storefront, Postman) stays stateless: the storefront needs no login, scripts use admin bearer tokens.
        $middleware->statefulApi();
        // An API call without a login is a 401 (never a redirect to a login page: there is none), whatever its Accept header says (CSV downloads).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

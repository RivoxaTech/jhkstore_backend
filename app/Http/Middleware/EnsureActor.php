<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum tokens are shared by admins and customers; this makes sure a token is used only on its own side.
 * Usage: ->middleware('actor:admin') or ->middleware('actor:customer').
 */
class EnsureActor
{
    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $user = $request->user();
        $expected = $actor === 'admin' ? Admin::class : User::class;

        if (! $user instanceof $expected || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}

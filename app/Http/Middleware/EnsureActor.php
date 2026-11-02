<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes sure the caller is an active admin (a Sanctum token or session of anything else is refused). Usage: ->middleware('actor:admin').
 */
class EnsureActor
{
    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $user = $request->user();
        if (! $user instanceof Admin || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}

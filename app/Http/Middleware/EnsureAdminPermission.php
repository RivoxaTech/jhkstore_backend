<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.can:<permission>[,<permission>]` – the logged-in admin needs at least one of the permissions (super_admin passes everything).
 * Runs after `actor:admin`, so an unauthenticated caller already got 401; a missing permission is 403.
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! collect($permissions)->contains(fn ($p) => $admin->can($p))) {
            return response()->json(['message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\SalesChannel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads ?channel=standard|instadent (default standard) and stores the SalesChannel on the request (`channel` attribute).
 * A channel with `requires_login` (Instadent) answers 401 to guests, so its catalogue cannot be read even by calling the API directly.
 */
class ResolveChannel
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) ($request->query('channel') ?? $request->input('channel') ?? SalesChannel::STANDARD);
        $channel = SalesChannel::query()->where('code', $code)->where('is_active', true)->first();

        if (! $channel) {
            return response()->json(['message' => 'Unknown sales channel.', 'errors' => ['channel' => ['Unknown sales channel.']]], 422);
        }

        if ($channel->requires_login) {
            $user = $request->user('sanctum'); // optional login: a bearer token of a customer
            if (! $user instanceof User || ! $user->is_active) {
                return response()->json(['message' => 'Please log in to use '.$channel->name.'.'], 401);
            }
        }

        $request->attributes->set('channel', $channel);

        return $next($request);
    }
}

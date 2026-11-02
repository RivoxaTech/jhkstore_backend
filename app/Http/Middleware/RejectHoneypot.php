<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honeypot for the public order endpoints (no captcha). The storefront adds a hidden form field (config('shop.honeypot_field'), default
 * `website`) that people never see and leave empty; bots fill every field. A request that fills it is refused with a plain 422 that
 * says nothing about why. Sending the field is optional, so an API client that does not know about it works unchanged.
 */
class RejectHoneypot
{
    public function handle(Request $request, Closure $next): Response
    {
        $field = (string) config('shop.honeypot_field');

        if ($field !== '' && filled($request->input($field))) {
            Log::info('Honeypot field filled, request refused.', ['ip' => $request->ip(), 'path' => $request->path()]);

            return response()->json(['message' => 'The request could not be processed.', 'code' => 'rejected'], 422);
        }

        return $next($request);
    }
}

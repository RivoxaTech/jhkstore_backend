<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Rebuild;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Website tab: the state of the rebuild of the static storefront and the "Rebuild website now" button (permission `settings.website`). */
class WebsiteController extends Controller
{
    /** Pending changes, last request, last result. Never the hook address or the secret (those are advanced settings). */
    public function status(Rebuild $rebuild): JsonResponse
    {
        return response()->json($rebuild->status());
    }

    /** Calls the hook now (a failure is an answer, not an error: the panel shows it). */
    public function rebuild(Request $request, Rebuild $rebuild): JsonResponse
    {
        if (! $rebuild->configured()) {
            return response()->json(['message' => 'No rebuild hook is set yet. Ask your developer to add it in Settings > Website.'] + $rebuild->status(), 422);
        }
        $result = $rebuild->now($request->user());

        return response()->json(['result' => ['ok' => $result['ok'], 'error' => $result['error'], 'at' => $result['at']]] + $rebuild->status());
    }
}

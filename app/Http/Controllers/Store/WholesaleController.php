<?php

namespace App\Http\Controllers\Store;

use App\Domain\Wholesale\Actions\SubmitWholesaleRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\WholesaleRequestRequest;
use Illuminate\Http\JsonResponse;

class WholesaleController extends Controller
{
    /**
     * A guest asks for a wholesale price. Answers only the reference (WS-2026-000001): nothing about other requests or customers.
     * Limits: 5 an hour per IP and 5 an hour per phone number; the honeypot field must stay empty.
     */
    public function store(WholesaleRequestRequest $request, SubmitWholesaleRequest $submit): JsonResponse
    {
        $row = $submit->handle($request->validated());

        return response()->json(['data' => ['reference' => $row->reference]], 201);
    }
}

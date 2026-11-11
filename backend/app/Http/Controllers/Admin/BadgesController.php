<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Support\Badges;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET admin/badges: what the panel polls every few seconds, in ONE call: `orders` (pending count, proofs to check, version) for an admin who holds
 * `orders.view` and `wholesale` (new count, version) for one who holds `wholesale.view`. A section the admin may not see is left out, so the answer
 * never tells more than the two single endpoints (`orders/pending-count`, `wholesale/new-count`), which stay.
 */
class BadgesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $request->user();
        $out = [];
        if (AdminAccess::allows($admin, 'orders.view')) {
            $out['orders'] = Badges::orders();
        }
        if (AdminAccess::allows($admin, 'wholesale.view')) {
            $out['wholesale'] = Badges::wholesale();
        }

        return response()->json($out);
    }
}

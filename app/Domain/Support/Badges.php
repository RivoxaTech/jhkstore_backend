<?php

namespace App\Domain\Support;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\Payment;
use App\Domain\Wholesale\Models\WholesaleRequest;

/**
 * The numbers behind the sidebar badges and the bell, and the `version` of each list: a fingerprint that changes whenever an order / request is
 * placed or changes. The panel polls them every few seconds (one call: GET admin/badges) and reloads the list it shows when a version moved.
 */
class Badges
{
    /**
     * `count` (the badge) is the number of ORDERS that wait for the shop: the pending ones. An order whose bank transfer proof is waiting to be checked is
     * still ONE order (it is pending too), so the badge does not count it twice. `proofs` says how many of them have a proof to check: the panel adds it to
     * its chime, so a proof that arrives for an order that was already waiting still rings.
     */
    public static function orders(): array
    {
        $pending = Order::query()->where('status', 'pending')->count();
        $proofs = Payment::query()->where('payable_type', Payment::ORDER)->where('proof_status', Payment::PROOF_SUBMITTED_STATUS)
            ->whereIn('payable_id', Order::query()->where('status', 'pending')->select('id'))->count();
        $o = Order::query()->selectRaw('count(*) as c, max(id) as m, max(updated_at) as u')->first();
        $p = Payment::query()->where('payable_type', Payment::ORDER)->max('updated_at');

        return ['count' => $pending, 'pending' => $pending, 'proofs' => $proofs, 'version' => md5($o->c.'|'.$o->m.'|'.$o->u.'|'.$p)];
    }

    /** Requests that are still `new`. */
    public static function wholesale(): array
    {
        $w = WholesaleRequest::query()->selectRaw('count(*) as c, max(id) as m, max(updated_at) as u, sum(status = ?) as n', ['new'])->first();

        return ['count' => (int) $w->n, 'version' => md5($w->c.'|'.$w->m.'|'.$w->u)];
    }
}

<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Wholesale\Models\WholesaleRequest;
use App\Support\Audit;

/** The admin sets the status and writes a note on a wholesale request; who and when are recorded, and the change is audited. */
class UpdateWholesaleRequest
{
    /** @param  array{status?:string, admin_note?:?string}  $data */
    public function handle(WholesaleRequest $request, array $data, Admin $actor): WholesaleRequest
    {
        $before = $request->only(['status', 'admin_note']);

        if (array_key_exists('status', $data)) {
            $request->status = $data['status'];
        }
        if (array_key_exists('admin_note', $data)) {
            $request->admin_note = trim((string) $data['admin_note']) ?: null;
        }
        if ($request->isDirty()) {
            $request->handled_at = now();
            $request->handled_by = $actor->id;
            $request->save();
            Audit::log($actor, 'wholesale.updated', $request, ['from' => $before, 'to' => $request->only(['status', 'admin_note'])]);
        }

        return $request;
    }
}

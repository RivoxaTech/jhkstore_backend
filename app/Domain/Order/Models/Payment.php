<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Polymorphic payment row (`payable_type` = order today; other things that can be paid for later).
 *  cash on delivery: cod_pending -> cod_collected
 *  bank transfer:    awaiting_proof -> proof_submitted -> paid (the admin verified it); a rejected proof goes back to awaiting_proof
 * `proof_status` (none | submitted | verified | rejected) follows the screenshot. The screenshot itself is the medialibrary collection `proof`
 * on the PRIVATE disk: it has no public URL, the admin reads it through an authenticated route.
 */
class Payment extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const ORDER = 'order';

    public const COD_PENDING = 'cod_pending';
    public const COD_COLLECTED = 'cod_collected';
    public const AWAITING_PROOF = 'awaiting_proof';
    public const PROOF_SUBMITTED = 'proof_submitted';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';

    public const PROOF_NONE = 'none';
    public const PROOF_SUBMITTED_STATUS = 'submitted';
    public const PROOF_VERIFIED = 'verified';
    public const PROOF_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'verified_at' => 'datetime', 'response' => 'array', 'bank_snapshot' => 'array'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof')->singleFile()->useDisk('local');   // `local` is the private disk (storage/app/private)
    }
}

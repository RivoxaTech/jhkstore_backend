<?php

namespace App\Domain\Address\Models;

use App\Domain\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved delivery address of a customer.
 * line1 = house no. / building / apartment, area = area / street / sector / village.
 */
class UserAddress extends Model
{
    public const TYPES = ['home', 'work', 'other'];

    /** Fields shared with the order snapshot (order_addresses). */
    public const SNAPSHOT_FIELDS = [
        'name', 'phone', 'alt_phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country', 'tax_number', 'latitude', 'longitude',
    ];

    protected $fillable = [
        'user_id', 'label', 'type', 'name', 'phone', 'alt_phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode',
        'country', 'tax_number', 'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

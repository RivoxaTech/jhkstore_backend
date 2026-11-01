<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the key/value `settings` table. Read and write through App\Domain\Support\Settings, not directly. */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['group_key', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}

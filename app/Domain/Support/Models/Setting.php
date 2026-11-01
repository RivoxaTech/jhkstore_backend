<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One row of the key/value `settings` table. Read and write through App\Domain\Support\Settings, not directly.
 *
 * The rows of the `file` settings (logo, favicon, share image) own their picture: one file in the `file` collection,
 * uploaded through the admin Settings page; the row's value is the id of that media item.
 */
class Setting extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'settings';

    protected $fillable = ['group_key', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile()->useDisk('public');
    }
}

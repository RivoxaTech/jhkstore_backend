<?php

use App\Domain\Auth\AdminAccess;
use Illuminate\Database\Migrations\Migration;

/**
 * Settings > E-mail adds the permission `settings.mail`. A permission an update adds starts with its default (the developer has it, the admin does not;
 * what the developer switched on or off stays), so the developer of an existing shop sees the new tab after `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        AdminAccess::sync();
    }

    public function down(): void
    {
        // the permission stays: nothing to undo
    }
};

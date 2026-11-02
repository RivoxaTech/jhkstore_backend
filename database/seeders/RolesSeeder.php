<?php

namespace Database\Seeders;

use App\Domain\Auth\AdminAccess;
use Illuminate\Database\Seeder;

/** Writes the admin permissions and the roles developer and admin (see App\Domain\Auth\AdminAccess). Safe to run again: roles that exist keep the permissions the developer set. */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        AdminAccess::sync();
    }
}

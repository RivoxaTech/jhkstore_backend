<?php

namespace Database\Seeders;

use App\Domain\Auth\AdminAccess;
use Illuminate\Database\Seeder;

/** Writes the admin permissions and the roles super_admin, manager and staff (see App\Domain\Auth\AdminAccess). Safe to run again: roles are reset to the matrix. */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        AdminAccess::sync();
    }
}

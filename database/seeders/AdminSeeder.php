<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\Admin;
use Illuminate\Database\Seeder;
use App\Domain\Auth\AdminAccess;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SEED_ADMIN_USERNAME', 'admin');
        $password = env('SEED_ADMIN_PASSWORD', 'admin');

        // The demo credentials are for local development only.
        if (app()->isProduction() && (strlen($password) < 12 || $password === $username)) {
            throw new \RuntimeException('Refusing to seed a weak admin password in production. Set SEED_ADMIN_PASSWORD.');
        }

        AdminAccess::sync();   // permissions and roles exist before the first admin gets one

        $admin = Admin::firstOrCreate(
            ['username' => $username],
            ['name' => 'Administrator', 'password' => $password, 'is_active' => true],
        );
        $admin->assignRole(AdminAccess::SUPER_ADMIN);
    }
}

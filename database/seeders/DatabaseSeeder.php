<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The base data every install needs: the default settings, the admin roles and the first admin user (a super admin).
 * Sample data for trying the shop out is a separate, optional seeder: php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RolesSeeder::class,
            AdminSeeder::class,
        ]);
    }
}

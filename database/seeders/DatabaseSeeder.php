<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The base data every install needs: the default settings, the two roles (developer, admin) and the two admin accounts.
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

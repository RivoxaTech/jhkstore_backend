<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            SalesChannelSeeder::class,
            SettingsSeeder::class,
            AdminSeeder::class,
            CustomerSeeder::class,
            InHouseCompanySeeder::class,
            CategorySeeder::class,
        ]);
    }
}

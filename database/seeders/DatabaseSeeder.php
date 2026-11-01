<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SalesChannelSeeder::class,
            SettingsSeeder::class,
            AdminSeeder::class,
            CustomerSeeder::class,
            CategorySeeder::class,
            TagSeeder::class,
        ]);
    }
}

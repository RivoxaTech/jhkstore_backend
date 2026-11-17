<?php

namespace Database\Seeders;

use App\Domain\Support\Settings;
use Illuminate\Database\Seeder;

/** Writes the default value of every setting that has no row yet (values an admin already changed are kept). */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        app(Settings::class)->seedDefaults();
    }
}

<?php

namespace Tests;

use App\Domain\Auth\Models\Admin;
use Database\Seeders\LookupSeeder;
use Database\Seeders\SalesChannelSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Seed the lookup data every API test depends on. */
    protected function seedBasics(): void
    {
        $this->seed([LookupSeeder::class, SalesChannelSeeder::class, SettingsSeeder::class]);
    }

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::create(['username' => 'tester', 'name' => 'Tester', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }
}

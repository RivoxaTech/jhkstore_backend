<?php

namespace Database\Seeders;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Creates the two admin accounts (and nothing else can create more: there is no screen for it):
 *
 *   developer  SEED_DEVELOPER_USERNAME / SEED_DEVELOPER_PASSWORD (+ _NAME, _EMAIL)   builds the site, never sees orders
 *   admin      SEED_ADMIN_USERNAME / SEED_ADMIN_PASSWORD (+ _NAME, _EMAIL)           the client
 *
 * An account that already exists keeps its password (set a new one with tinker or by deleting the row and seeding again).
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            AdminAccess::DEVELOPER => [
                'username' => env('SEED_DEVELOPER_USERNAME', 'developer'),
                'password' => env('SEED_DEVELOPER_PASSWORD', 'developer'),
                'name' => env('SEED_DEVELOPER_NAME', 'Developer'),
                'email' => env('SEED_DEVELOPER_EMAIL'),
            ],
            AdminAccess::ADMIN => [
                'username' => env('SEED_ADMIN_USERNAME', 'admin'),
                'password' => env('SEED_ADMIN_PASSWORD', 'admin'),
                'name' => env('SEED_ADMIN_NAME', 'Administrator'),
                'email' => env('SEED_ADMIN_EMAIL'),
            ],
        ];

        // The demo credentials are for local development only.
        foreach ($accounts as $role => $account) {
            if (app()->isProduction() && (strlen($account['password']) < 12 || $account['password'] === $account['username'])) {
                throw new \RuntimeException('Refusing to seed a weak '.$role.' password in production. Set SEED_'.strtoupper($role).'_PASSWORD (12+ characters).');
            }
        }
        if ($accounts[AdminAccess::DEVELOPER]['username'] === $accounts[AdminAccess::ADMIN]['username']) {
            throw new \RuntimeException('SEED_DEVELOPER_USERNAME and SEED_ADMIN_USERNAME must be different.');
        }

        AdminAccess::sync();   // permissions and roles exist before the accounts get one

        foreach ($accounts as $role => $account) {
            $admin = Admin::firstOrCreate(
                ['username' => $account['username']],
                ['name' => $account['name'], 'email' => $account['email'] ?: null, 'password' => $account['password'], 'is_active' => true],
            );
            $admin->syncRoles($role);
        }
    }
}

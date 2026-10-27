<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_CUSTOMER_EMAIL', 'test@gmail.com');
        $password = env('SEED_CUSTOMER_PASSWORD', 'test');

        if (app()->isProduction() && strlen($password) < 12) {
            throw new \RuntimeException('Refusing to seed a weak customer password in production. Set SEED_CUSTOMER_PASSWORD.');
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => 'Test User',
                'mobile' => '9999999999',
                'password' => $password,
                'professional_type' => 'dentist',
                'is_active' => true,
            ],
        );
    }
}

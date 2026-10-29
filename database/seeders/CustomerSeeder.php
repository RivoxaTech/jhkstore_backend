<?php

namespace Database\Seeders;

use App\Domain\Address\Actions\SaveAddress;
use App\Domain\Auth\Models\User;
use App\Domain\Reward\Actions\RewardLedger;
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

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => 'Test User',
                'mobile' => '9999999999',
                'password' => $password,
                'professional_type' => 'dentist',
                'is_active' => true,
            ],
        );

        // Demo data for trying the cart and checkout: 500 coins and one default address (only when the account is new).
        if ($user->wasRecentlyCreated && ! app()->isProduction()) {
            app(RewardLedger::class)->grant($user->id, 500, 'Welcome coins (demo data)');
            app(SaveAddress::class)->create($user, [
                'label' => 'Clinic', 'type' => 'clinic', 'name' => 'Test User', 'phone' => '03001234567', 'alt_phone' => '03111234567',
                'line1' => 'Smile Dental Clinic, Shop 4, Medicare Plaza', 'area' => 'Block B, Gulberg III', 'landmark' => 'Near the main boulevard',
                'city' => 'Lahore', 'state' => 'Punjab', 'postcode' => '54660', 'country' => 'Pakistan',
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesChannelSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ([
            ['code' => 'standard', 'name' => 'Standard Delivery', 'requires_login' => false, 'delivery_days' => 5],
            ['code' => 'instadent', 'name' => 'Instadent', 'requires_login' => true, 'delivery_days' => 3],
        ] as $channel) {
            DB::table('sales_channels')->updateOrInsert(
                ['code' => $channel['code']],
                $channel + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}

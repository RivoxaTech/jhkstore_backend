<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_delivery_settings_use_live_site_defaults(): void
    {
        $this->seedBasics();

        $this->getJson('/api/v1/store/settings/delivery')
            ->assertOk()
            ->assertJsonPath('delivery_fee', 95)
            ->assertJsonPath('free_delivery_min_cart', 2800)
            ->assertJsonFragment(['code' => 'standard', 'delivery_days' => 5])
            ->assertJsonFragment(['code' => 'instadent', 'delivery_days' => 3]);
    }

    public function test_admin_can_change_delivery_charge_and_free_limit(): void
    {
        $this->seedBasics();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/admin/settings/delivery', [
            'delivery_fee' => 120,
            'free_delivery_min_cart' => 3500,
            'channels' => [['code' => 'standard', 'delivery_days' => 6]],
        ])->assertOk()->assertJsonPath('delivery_fee', 120);

        $this->getJson('/api/v1/store/settings/delivery')
            ->assertJsonPath('delivery_fee', 120)
            ->assertJsonPath('free_delivery_min_cart', 3500)
            ->assertJsonFragment(['code' => 'standard', 'delivery_days' => 6]);
    }
}

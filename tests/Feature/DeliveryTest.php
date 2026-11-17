<?php

namespace Tests\Feature;

use App\Domain\Delivery\Models\DeliveryZone;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Delivery methods, delivery zones (matching, fees, minimum order, ETA), the unmatched-address policy, the zone manager API and the pending-fee flow. */
class DeliveryTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 5);
        $this->setSetting('free_delivery_min_cart', 0);
        $this->setSetting('delivery_days', 5);
    }

    private function summary(int $variant, array $extra = [], int $qty = 1): array
    {
        return $this->quote([$variant => $qty], [], $extra)->assertOk()->json('data.summary');
    }

    // ------------------------------------------------------------------ the methods

    public function test_flat_charges_the_fee_on_every_order_whatever_the_cart_value(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'flat');
        $this->setSetting('free_delivery_min_cart', 100);   // ignored by the flat method

        $s = $this->summary($variant, [], 5);

        $this->assertSame('flat', $s['delivery_method']);
        $this->assertEquals(5, $s['delivery_fee']);
        $this->assertFalse($s['delivery_free']);
        $this->assertFalse($s['delivery_pending']);
        $this->assertNull($s['amount_for_free_delivery']);
        $this->assertEquals(765, $s['grand_total']);
    }

    public function test_free_delivery_is_always_free(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'free');

        $s = $this->summary($variant);

        $this->assertEquals(0, $s['delivery_fee']);
        $this->assertTrue($s['delivery_free']);
        $this->assertEquals(160, $s['grand_total']);

        $order = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated()->json('data.order_number'))->first();
        $this->assertEquals(0, $order->shipping_fee);
        $this->assertFalse($order->delivery_fee_pending);
    }

    public function test_free_above_is_free_from_the_discounted_items_total(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'free_above');
        $this->setSetting('free_delivery_min_cart', 160);

        $s = $this->summary($variant);
        $this->assertTrue($s['delivery_free']);
        $this->assertEquals(0, $s['delivery_fee']);

        $this->makeDiscount(['value' => 10]);                  // 160 -> 144: below the free-delivery amount now
        $s = $this->summary($variant);
        $this->assertFalse($s['delivery_free']);
        $this->assertEquals(5, $s['delivery_fee']);
        $this->assertEquals(16, $s['amount_for_free_delivery']);
        $this->assertEquals(149, $s['grand_total']);
    }

    public function test_the_quote_carries_every_delivery_field(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $s = $this->summary($variant);

        foreach (['delivery_method', 'delivery_fee', 'delivery_pending', 'delivery_needs_address', 'delivery_zone', 'delivery_eta_text', 'delivery_free', 'amount_for_free_delivery'] as $key) {
            $this->assertArrayHasKey($key, $s);
        }
        $this->assertNull($s['delivery_zone']);
    }

    // ------------------------------------------------------------------ owner confirms: the pending-fee flow

    public function test_with_owner_confirms_the_fee_is_unknown_in_the_quote(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'owner_confirms');

        $res = $this->quote([$variant => 1])->assertOk();

        $this->assertNull($res->json('data.summary.delivery_fee'));
        $this->assertTrue($res->json('data.summary.delivery_pending'));
        $this->assertEquals(160, $res->json('data.summary.grand_total'));   // the fee is not part of the total yet
        $this->assertTrue($res->json('data.can_checkout'));
    }

    public function test_the_pending_fee_flow_from_the_order_to_the_final_total(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'owner_confirms');
        $contact = $this->contact(['phone' => '+1 555 777 0001']);

        $placed = $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $contact]))->assertCreated();
        $number = $placed->json('data.order_number');
        $placed->assertJsonPath('data.delivery_fee_pending', true)->assertJsonPath('data.totals.delivery_fee', null)->assertJsonPath('data.totals.delivery_fee_pending', true);
        $order = Order::where('order_number', $number)->first();
        $this->assertNull($order->shipping_fee);
        $this->assertTrue($order->delivery_fee_pending);
        $this->assertEquals(160, $order->grand_total);

        // the customer sees "to be confirmed"
        $this->lookup($number, '+1 555 777 0001')->assertOk()->assertJsonPath('data.delivery_fee_pending', true)->assertJsonPath('data.totals.delivery_fee', null);

        // the admin sees it and cannot confirm the order yet
        $this->asAdmin();
        $detail = $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk();
        $detail->assertJsonPath('data.actions.needs_delivery_fee', true)->assertJsonPath('data.actions.blocked_reason', 'delivery_fee_pending')->assertJsonPath('data.delivery.fee_pending', true);
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422)->assertJsonPath('code', 'delivery_fee_pending');
        $this->assertSame('pending', $order->fresh()->status->value);

        // the fee is set: the total, the payment and the customer's total follow
        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 12.5])->assertOk()
            ->assertJsonPath('data.totals.delivery_fee', 12.5)->assertJsonPath('data.totals.grand_total', 172.5)->assertJsonPath('data.actions.needs_delivery_fee', false);
        $order->refresh();
        $this->assertEquals(12.5, $order->shipping_fee);
        $this->assertFalse($order->delivery_fee_pending);
        $this->assertEquals(172.5, $order->grand_total);
        $this->assertEquals(172.5, DB::table('payments')->where('payable_id', $order->id)->value('amount'));
        $this->assertEquals(172.5, DB::table('customers')->where('id', $order->customer_id)->value('total_spent'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.delivery_fee_set', 'subject_id' => $order->id]);
        $this->assertSame('Delivery fee confirmed: 12.50', collect($this->getJson(self::ADMIN."/orders/{$order->id}")->json('data.timeline'))->last()['note']);

        // the customer sees the final total
        $this->lookup($number, '+1 555 777 0001')->assertOk()->assertJsonPath('data.delivery_fee_pending', false)->assertJsonPath('data.totals.delivery_fee', 12.5)->assertJsonPath('data.grand_total', 172.5);

        // now the order can be confirmed; the fee cannot be set a second time
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 1])->assertStatus(422)->assertJsonPath('code', 'delivery_fee_not_pending');
    }

    public function test_the_fee_is_validated_and_a_fee_of_zero_is_allowed(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'owner_confirms');
        $order = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1]))->json('data.order_number'))->first();
        $this->asAdmin();

        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", [])->assertStatus(422)->assertJsonValidationErrors('fee');
        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => -1])->assertStatus(422)->assertJsonValidationErrors('fee');
        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('fee');
        $this->assertTrue($order->fresh()->delivery_fee_pending);

        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 0])->assertOk()->assertJsonPath('data.totals.grand_total', 160);
    }

    public function test_a_fee_added_to_a_tax_exclusive_order_leaves_the_tax_alone(): void
    {
        $this->setSetting('tax_inclusive', false);
        $this->setSetting('delivery_method', 'owner_confirms');
        $variant = $this->variantOf($this->shopProduct());
        $order = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated()->json('data.order_number'))->first();
        $this->assertEquals(188.8, $order->grand_total);   // 160 + 18 % tax
        $this->asAdmin();

        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 10])->assertOk()->assertJsonPath('data.totals.grand_total', 198.8);
        $this->assertEquals(28.8, $order->fresh()->tax_total);
    }

    public function test_the_fee_of_a_cancelled_or_rejected_order_cannot_be_set(): void
    {
        $this->setSetting('delivery_method', 'owner_confirms');
        $variant = $this->variantOf($this->shopProduct());
        $order = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1]))->json('data.order_number'))->first();
        $this->asAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'rejected', 'reason' => 'Not today'])->assertOk();

        $this->putJson(self::ADMIN."/orders/{$order->id}/delivery-fee", ['fee' => 3])->assertStatus(422)->assertJsonPath('code', 'order_closed');
    }

    // ------------------------------------------------------------------ zones: matching

    public function test_a_city_zone_matches_whatever_the_case_and_spacing(): void
    {
        $this->makeZone(['name' => 'Metro', 'match_values' => ['Springfield', 'Shelbyville'], 'fee' => 7]);

        $this->assertTrue((new DeliveryZone(['match_type' => 'city', 'match_values' => ['Springfield', 'Shelbyville']]))->matches(['city' => '  springfield ']));
        $this->assertTrue((new DeliveryZone(['match_type' => 'city', 'match_values' => ['New  York']]))->matches(['city' => 'new york']));
        $this->assertFalse((new DeliveryZone(['match_type' => 'city', 'match_values' => ['Springfield']]))->matches(['city' => 'Spring']));   // equality, not "contains"
        $this->assertFalse((new DeliveryZone(['match_type' => 'city', 'match_values' => ['Springfield']]))->matches(['area' => 'Springfield']));
        $this->assertFalse((new DeliveryZone(['match_type' => 'city', 'match_values' => []]))->matches(['city' => '']));
    }

    public function test_an_area_zone_matches_the_area_and_a_postcode_zone_matches_exactly_or_by_prefix(): void
    {
        $area = new DeliveryZone(['match_type' => 'area', 'match_values' => ['Downtown']]);
        $this->assertTrue($area->matches(['city' => 'Elsewhere', 'area' => 'DOWNTOWN']));
        $this->assertFalse($area->matches(['city' => 'Downtown']));

        $post = new DeliveryZone(['match_type' => 'postcode', 'match_values' => ['12345', '54*', 'sw1a 1aa']]);
        $this->assertTrue($post->matches(['postcode' => '12345']));
        $this->assertFalse($post->matches(['postcode' => '123456']));     // exact, not a prefix
        $this->assertTrue($post->matches(['postcode' => '54000']));        // the 54* prefix
        $this->assertTrue($post->matches(['postcode' => '54']));
        $this->assertFalse($post->matches(['postcode' => '45400']));
        $this->assertTrue($post->matches(['postcode' => 'SW1A1AA']));      // case and spaces do not matter
        $this->assertFalse($post->matches(['postcode' => '']));
    }

    public function test_the_first_active_zone_by_sort_order_wins(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $this->makeZone(['name' => 'Disabled', 'fee' => 1, 'sort_order' => 1, 'is_active' => false]);
        $second = $this->makeZone(['name' => 'Wide', 'match_type' => 'city', 'fee' => 9, 'sort_order' => 3]);
        $first = $this->makeZone(['name' => 'Narrow', 'match_type' => 'area', 'match_values' => ['Downtown'], 'fee' => 4, 'sort_order' => 2]);

        $s = $this->summary($variant, ['address' => ['city' => 'Springfield', 'area' => 'Downtown']]);
        $this->assertSame($first->id, $s['delivery_zone']['id']);
        $this->assertSame('Narrow', $s['delivery_zone']['name']);
        $this->assertEquals(4, $s['delivery_fee']);

        $s = $this->summary($variant, ['address' => ['city' => 'Springfield', 'area' => 'Uptown']]);
        $this->assertSame($second->id, $s['delivery_zone']['id']);
        $this->assertEquals(9, $s['delivery_fee']);
    }

    public function test_a_zones_store_asks_for_the_address_before_it_can_name_a_fee(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $this->makeZone();

        foreach ([[], ['address' => []], ['address' => ['city' => '  ', 'postcode' => '']]] as $extra) {
            $s = $this->summary($variant, $extra);
            $this->assertNull($s['delivery_fee']);
            $this->assertTrue($s['delivery_needs_address']);
            $this->assertFalse($s['delivery_pending']);
            $this->assertEquals(160, $s['grand_total']);
        }
    }

    public function test_the_zone_decides_the_free_amount_the_eta_and_the_minimum_order(): void
    {
        $this->setSetting('delivery_method', 'zones');
        $this->setSetting('delivery_eta_text', 'Usually 3 to 5 days');
        $this->setSetting('min_order_amount', 50);
        $cheap = $this->variantOf($this->shopProduct(['mrp' => 20, 'selling_price' => 20, 'price_tiers' => []]));
        $normal = $this->variantOf($this->shopProduct());
        $this->makeZone(['name' => 'Local', 'match_values' => ['Springfield'], 'fee' => 6, 'free_above' => 150, 'min_order' => 10, 'eta_text' => 'Tomorrow']);
        $this->makeZone(['name' => 'Far', 'match_values' => ['Farville'], 'fee' => 20, 'min_order' => 300]);
        $local = ['address' => ['city' => 'Springfield']];

        // below the store minimum of 50, but the zone's own minimum (10) replaces it
        $res = $this->quote([$cheap => 1], [], $local)->assertOk();
        $this->assertTrue($res->json('data.can_checkout'));
        $this->assertSame('Tomorrow', $res->json('data.summary.delivery_eta_text'));
        $this->assertEquals(10, $res->json('data.summary.min_order_amount'));
        $this->assertEquals(6, $res->json('data.summary.delivery_fee'));

        // free above the zone's own amount
        $s = $this->summary($normal, $local);
        $this->assertTrue($s['delivery_free']);
        $this->assertEquals(0, $s['delivery_fee']);
        $this->assertEquals(150, $s['free_delivery_min_cart']);

        // a zone with a higher minimum blocks a small order
        $res = $this->quote([$normal => 1], [], ['address' => ['city' => 'Farville']])->assertOk();
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame('below_min_order', $res->json('data.issues.0.code'));
        $this->assertSame('Usually 3 to 5 days', $res->json('data.summary.delivery_eta_text'));   // the zone has none: the store's text
        $this->placeOrder($this->orderBody([$normal => 1], ['address' => $this->addressData(['city' => 'Farville'])]))->assertStatus(422)->assertJsonPath('code', 'below_min_order');
        $this->placeOrder($this->orderBody([$normal => 2], ['address' => $this->addressData(['city' => 'Farville'])]))->assertCreated();   // 2 x 160 = 320 reaches the zone's minimum of 300
    }

    public function test_an_order_in_a_zone_takes_its_fee_and_remembers_the_zone(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $zone = $this->makeZone(['match_type' => 'postcode', 'match_values' => ['12*'], 'fee' => 8]);

        $res = $this->placeOrder($this->orderBody([$variant => 1], ['address' => $this->addressData(['city' => 'Anywhere', 'postcode' => '12345'])]))->assertCreated();

        $res->assertJsonPath('data.totals.delivery_fee', 8)->assertJsonPath('data.grand_total', 168);
        $order = Order::where('order_number', $res->json('data.order_number'))->first();
        $this->assertSame($zone->id, (int) $order->delivery_zone_id);
        $this->assertFalse($order->delivery_fee_pending);
    }

    // ------------------------------------------------------------------ zones: an address no zone covers

    public function test_an_unmatched_address_is_refused_by_default(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $this->makeZone(['match_values' => ['Springfield']]);
        $elsewhere = ['address' => ['city' => 'Nowhere']];

        $res = $this->quote([$variant => 1], [], $elsewhere)->assertOk();
        $this->assertFalse($res->json('data.can_checkout'));
        $this->assertSame('delivery_not_available', $res->json('data.issues.0.code'));
        $this->assertNull($res->json('data.summary.delivery_fee'));

        $this->placeOrder($this->orderBody([$variant => 1], ['address' => $this->addressData(['city' => 'Nowhere'])]))
            ->assertStatus(422)->assertJsonPath('code', 'delivery_not_available');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
    }

    public function test_an_unmatched_address_can_be_left_to_the_owner_or_charged_the_flat_fee(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $this->makeZone(['match_values' => ['Springfield']]);
        $elsewhere = ['address' => ['city' => 'Nowhere']];

        $this->setSetting('delivery_unmatched', 'owner_confirms');
        $s = $this->summary($variant, $elsewhere);
        $this->assertTrue($s['delivery_pending']);
        $this->assertNull($s['delivery_fee']);
        $order = Order::where('order_number', $this->placeOrder($this->orderBody([$variant => 1], ['address' => $this->addressData(['city' => 'Nowhere'])]))->assertCreated()->json('data.order_number'))->first();
        $this->assertTrue($order->delivery_fee_pending);
        $this->assertNull($order->shipping_fee);
        $this->assertNull($order->delivery_zone_id);

        $this->setSetting('delivery_unmatched', 'flat');
        $s = $this->summary($variant, $elsewhere);
        $this->assertFalse($s['delivery_pending']);
        $this->assertEquals(5, $s['delivery_fee']);
        $this->placeOrder($this->orderBody([$variant => 1], ['address' => $this->addressData(['city' => 'Nowhere'])]))->assertCreated()->assertJsonPath('data.totals.delivery_fee', 5);
    }

    // ------------------------------------------------------------------ the zone manager API

    public function test_the_admin_manages_zones_and_every_change_is_audited(): void
    {
        $this->asAdmin();

        $created = $this->postJson(self::ADMIN.'/delivery-zones', [
            'name' => 'City centre', 'match_type' => 'postcode', 'match_values' => [' 54* ', '54*', '', '12345'], 'fee' => 4.5, 'free_above' => 100, 'min_order' => 20, 'eta_text' => '1 day',
        ])->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.match_values', ['54*', '12345'])->assertJsonPath('data.fee', 4.5)->assertJsonPath('data.free_above', 100)
            ->assertJsonPath('data.sort_order', 1)->assertJsonPath('data.is_active', true);

        $this->putJson(self::ADMIN."/delivery-zones/$id", ['name' => 'Centre', 'match_type' => 'city', 'match_values' => ['Springfield'], 'fee' => 3, 'free_above' => null, 'min_order' => null, 'eta_text' => null])
            ->assertOk()->assertJsonPath('data.name', 'Centre')->assertJsonPath('data.match_type', 'city')->assertJsonPath('data.free_above', null)->assertJsonPath('data.eta_text', null);
        $this->patchJson(self::ADMIN."/delivery-zones/$id/active", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $second = $this->postJson(self::ADMIN.'/delivery-zones', ['name' => 'Second', 'match_type' => 'area', 'match_values' => ['North'], 'fee' => 0])->assertCreated()->json('data.id');
        $this->assertSame([$id, $second], array_column($this->getJson(self::ADMIN.'/delivery-zones')->assertOk()->json('data'), 'id'));

        $this->putJson(self::ADMIN.'/delivery-zones/reorder', ['ids' => [$second, $id]])->assertOk();
        $this->assertSame([$second, $id], array_column($this->getJson(self::ADMIN.'/delivery-zones')->json('data'), 'id'));

        $this->deleteJson(self::ADMIN."/delivery-zones/$id")->assertOk();
        $this->assertSame([$second], array_column($this->getJson(self::ADMIN.'/delivery-zones')->json('data'), 'id'));
        $this->getJson(self::ADMIN."/delivery-zones/$id")->assertNotFound();

        foreach (['created', 'updated', 'toggled', 'reordered', 'deleted'] as $what) {
            $this->assertDatabaseHas('audit_logs', ['action' => "delivery_zone.$what", 'actor_type' => 'admin']);
        }
    }

    public function test_zones_are_validated(): void
    {
        $this->asAdmin();
        $ok = ['name' => 'Z', 'match_type' => 'city', 'match_values' => ['A'], 'fee' => 1];

        $this->postJson(self::ADMIN.'/delivery-zones', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'match_type', 'match_values', 'fee']);
        $this->postJson(self::ADMIN.'/delivery-zones', ['match_type' => 'country'] + $ok)->assertStatus(422)->assertJsonValidationErrors('match_type');
        $this->postJson(self::ADMIN.'/delivery-zones', ['match_values' => []] + $ok)->assertStatus(422)->assertJsonValidationErrors('match_values');
        $this->postJson(self::ADMIN.'/delivery-zones', ['fee' => -1] + $ok)->assertStatus(422)->assertJsonValidationErrors('fee');
        $this->postJson(self::ADMIN.'/delivery-zones', ['free_above' => -5] + $ok)->assertStatus(422)->assertJsonValidationErrors('free_above');
        $this->postJson(self::ADMIN.'/delivery-zones', ['name' => str_repeat('n', 121)] + $ok)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson(self::ADMIN.'/delivery-zones/reorder', ['ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('ids.0');
        $this->assertSame(0, DeliveryZone::count());
        $this->postJson(self::ADMIN.'/delivery-zones', $ok)->assertCreated();
    }

    public function test_a_zone_that_is_switched_off_or_deleted_stops_matching_at_once(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('delivery_method', 'zones');
        $zone = $this->makeZone(['fee' => 4]);
        $address = ['address' => ['city' => 'Springfield']];
        $this->assertEquals(4, $this->summary($variant, $address)['delivery_fee']);

        $zone->update(['is_active' => false]);
        $this->assertFalse($this->quote([$variant => 1], [], $address)->json('data.can_checkout'));

        $zone->update(['is_active' => true]);
        $zone->delete();
        $this->assertSame('delivery_not_available', $this->quote([$variant => 1], [], $address)->json('data.issues.0.code'));
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponUsage;
use App\Domain\Promotion\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The Discounts and Coupons screens' API: create, edit, validate, activate, delete, audit, the target pickers and the usage list. */
class PromotionAdminTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function discountBody(array $o = []): array
    {
        return $o + ['name' => 'Summer', 'type' => 'percent', 'value' => 15, 'scope' => 'store'];
    }

    private function couponBody(array $o = []): array
    {
        return $o + ['code' => 'summer15', 'name' => 'Summer coupon', 'type' => 'percent', 'value' => 15, 'scope' => 'store'];
    }

    // ------------------------------------------------------------------ discounts

    public function test_a_discount_is_created_with_its_targets_and_shown_with_their_names(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Cat A');
        $p1 = $this->makeProduct($category, ['name' => 'Alpha']);
        $p2 = $this->makeProduct($category, ['name' => 'Beta']);

        $res = $this->postJson(self::ADMIN.'/discounts', $this->discountBody([
            'scope' => 'products', 'target_ids' => [$p1->id, $p2->id, $p1->id], 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-31 23:59:59', 'priority' => 3,
        ]))->assertCreated();

        $res->assertJsonPath('data.name', 'Summer')->assertJsonPath('data.value', 15)->assertJsonPath('data.scope', 'products')->assertJsonPath('data.priority', 3)
            ->assertJsonPath('data.is_active', true)->assertJsonPath('data.state', 'scheduled')->assertJsonPath('data.targets_count', 2)
            ->assertJsonPath('data.target_ids', [$p1->id, $p2->id]);
        $this->assertSame(['Alpha', 'Beta'], array_column($res->json('data.targets'), 'name'));
        $this->assertSame(2, DB::table('discount_targets')->count());                       // the repeated id is stored once
        $this->assertDatabaseHas('audit_logs', ['action' => 'discount.created', 'actor_type' => 'admin']);

        $show = $this->getJson(self::ADMIN.'/discounts/'.$res->json('data.id'))->assertOk();
        $this->assertSame(['Alpha', 'Beta'], array_column($show->json('data.targets'), 'name'));
    }

    public function test_targets_follow_the_scope_and_are_replaced_on_update(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Cat B');
        $sub = $this->makeSub($category, 'Sub B');
        $product = $this->makeProduct($category);

        $id = $this->postJson(self::ADMIN.'/discounts', $this->discountBody(['scope' => 'categories', 'target_ids' => [$category->id]]))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('discount_targets', ['discount_id' => $id, 'type' => 'category', 'target_id' => $category->id]);

        $this->putJson(self::ADMIN."/discounts/$id", $this->discountBody(['scope' => 'sub_categories', 'target_ids' => [$sub->id]]))->assertOk()
            ->assertJsonPath('data.targets.0.name', 'Sub B');
        $this->assertSame([['sub_category', $sub->id]], DB::table('discount_targets')->where('discount_id', $id)->get(['type', 'target_id'])->map(fn ($r) => [$r->type, $r->target_id])->all());

        $this->putJson(self::ADMIN."/discounts/$id", $this->discountBody(['scope' => 'store']))->assertOk()->assertJsonPath('data.targets', []);
        $this->assertSame(0, DB::table('discount_targets')->count());                        // the whole store has no targets

        $this->putJson(self::ADMIN."/discounts/$id", $this->discountBody(['scope' => 'products', 'target_ids' => [$product->id], 'name' => 'Renamed', 'value' => 5]))->assertOk()
            ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.value', 5);
        $this->assertDatabaseHas('audit_logs', ['action' => 'discount.updated']);
    }

    public function test_discounts_are_validated(): void
    {
        $this->asAdmin();
        $post = fn (array $o) => $this->postJson(self::ADMIN.'/discounts', $this->discountBody($o));

        $this->postJson(self::ADMIN.'/discounts', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'type', 'value', 'scope']);
        $post(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $post(['type' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('type');
        $post(['value' => 0])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['value' => 0.001])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['value' => 100.01])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['type' => 'fixed', 'value' => 0])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['type' => 'fixed', 'value' => -5])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['scope' => 'galaxy'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $post(['scope' => 'products'])->assertStatus(422)->assertJsonValidationErrors('target_ids');
        $post(['scope' => 'products', 'target_ids' => []])->assertStatus(422)->assertJsonValidationErrors('target_ids');
        $post(['scope' => 'products', 'target_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('target_ids.0');
        $post(['scope' => 'categories', 'target_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('target_ids.0');
        $post(['scope' => 'sub_categories', 'target_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('target_ids.0');
        $post(['starts_at' => '2026-12-31', 'ends_at' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $post(['starts_at' => 'tomorrowish'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $post(['priority' => 5000])->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->assertSame(0, Discount::count());

        $post(['value' => 100])->assertCreated();                                          // the limits themselves are fine
        $post(['value' => 0.01, 'starts_at' => '', 'ends_at' => ''])->assertCreated()->assertJsonPath('data.starts_at', null);
        $post(['type' => 'fixed', 'value' => 0.5, 'priority' => null])->assertCreated()->assertJsonPath('data.priority', 0);
    }

    public function test_a_discount_is_listed_filtered_switched_and_deleted(): void
    {
        $this->asAdmin();
        $live = $this->makeDiscount(['name' => 'Live one']);
        $soon = $this->makeDiscount(['name' => 'Soon one', 'starts_at' => now()->addWeek()]);
        $over = $this->makeDiscount(['name' => 'Over one', 'ends_at' => now()->subWeek()]);
        $off = $this->makeDiscount(['name' => 'Off one', 'is_active' => false]);
        $names = fn (string $q = '') => collect($this->getJson(self::ADMIN.'/discounts'.$q)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Live one', 'Off one', 'Over one', 'Soon one'], $names());
        $this->assertSame(['Live one'], $names('?status=live'));
        $this->assertSame(['Soon one'], $names('?status=scheduled'));
        $this->assertSame(['Over one'], $names('?status=expired'));
        $this->assertSame(['Off one'], $names('?status=inactive'));
        $this->assertSame(['Soon one'], $names('?q=soon'));
        $this->getJson(self::ADMIN.'/discounts?status=weird')->assertStatus(422);
        $page = $this->getJson(self::ADMIN.'/discounts?per_page=2&page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(4, $page->json('meta.total'));
        $states = collect($this->getJson(self::ADMIN.'/discounts')->json('data'))->pluck('state', 'name')->all();
        $this->assertEqualsCanonicalizing(['Live one' => 'live', 'Soon one' => 'scheduled', 'Over one' => 'expired', 'Off one' => 'inactive'], $states);

        $this->patchJson(self::ADMIN."/discounts/{$off->id}/active", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.state', 'live');
        $this->patchJson(self::ADMIN."/discounts/{$off->id}/active", [])->assertStatus(422)->assertJsonValidationErrors('is_active');
        $this->patchJson(self::ADMIN."/discounts/{$live->id}/active", ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'discount.toggled', 'subject_id' => $live->id]);

        $this->deleteJson(self::ADMIN."/discounts/{$soon->id}")->assertOk();
        $this->assertNull(Discount::find($soon->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'discount.deleted']);
        $this->getJson(self::ADMIN."/discounts/{$soon->id}")->assertNotFound();
    }

    public function test_the_discounts_list_costs_a_fixed_number_of_queries(): void
    {
        $this->asAdmin();
        foreach (range(1, 3) as $i) {
            $this->makeDiscount(['name' => "D$i", 'scope' => 'products', 'targets' => [$this->makeProduct($this->makeCategory("C$i"))->id]]);
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ADMIN.'/discounts?per_page=50')->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $few = $count();
        foreach (range(4, 12) as $i) {
            $this->makeDiscount(['name' => "D$i"]);
        }

        $this->assertSame($few, $count());
    }

    // ------------------------------------------------------------------ coupons

    public function test_a_coupon_is_created_with_an_uppercase_code_and_its_limits(): void
    {
        $this->asAdmin();

        $res = $this->postJson(self::ADMIN.'/coupons', $this->couponBody([
            'description' => 'Welcome gift', 'min_order' => 50, 'max_discount' => 20, 'usage_limit' => 100, 'per_customer_limit' => 2, 'first_order_only' => true,
            'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2027-01-01 00:00:00',
        ]))->assertCreated();

        $res->assertJsonPath('data.code', 'SUMMER15')->assertJsonPath('data.description', 'Welcome gift')->assertJsonPath('data.min_order', 50)->assertJsonPath('data.max_discount', 20)
            ->assertJsonPath('data.usage_limit', 100)->assertJsonPath('data.per_customer_limit', 2)->assertJsonPath('data.first_order_only', true)->assertJsonPath('data.free_delivery', false)
            ->assertJsonPath('data.used_count', 0)->assertJsonPath('data.is_active', true)->assertJsonPath('data.state', 'scheduled');
        $this->assertArrayNotHasKey('priority', $res->json('data'));
        $this->assertDatabaseHas('coupons', ['code' => 'SUMMER15']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.created']);
    }

    public function test_a_free_delivery_coupon_has_no_value(): void
    {
        $this->asAdmin();

        $res = $this->postJson(self::ADMIN.'/coupons', ['code' => 'FREESHIP', 'name' => 'Free delivery', 'scope' => 'store', 'free_delivery' => true, 'value' => 50, 'type' => 'percent', 'max_discount' => 9])->assertCreated();

        $res->assertJsonPath('data.type', 'free_delivery')->assertJsonPath('data.value', null)->assertJsonPath('data.max_discount', null)->assertJsonPath('data.free_delivery', true);
        $this->postJson(self::ADMIN.'/coupons', ['code' => 'NOVALUE', 'name' => 'Broken', 'scope' => 'store'])->assertStatus(422)->assertJsonValidationErrors(['type', 'value']);
    }

    public function test_coupon_codes_are_validated_and_unique_whatever_the_case(): void
    {
        $this->asAdmin();
        $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => 'save10']))->assertCreated();

        $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => 'SAVE10']))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => ' Save10 ']))->assertStatus(422)->assertJsonValidationErrors('code');
        foreach (['ab', str_repeat('A', 33), 'has space', 'ünïcode', 'semi;colon', '', 'a/b'] as $bad) {
            $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => $bad]))->assertStatus(422)->assertJsonValidationErrors('code');
        }
        foreach (['ABC', str_repeat('Z', 32), 'A_B-9', 'x1-2_3'] as $good) {
            $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => $good]))->assertCreated();
        }
        $this->assertSame(['SAVE10', 'ABC', str_repeat('Z', 32), 'A_B-9', 'X1-2_3'], Coupon::orderBy('id')->pluck('code')->all());
    }

    public function test_coupon_limits_and_values_are_validated(): void
    {
        $this->asAdmin();
        $post = fn (array $o) => $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => 'T'.mt_rand(100, 999999)] + $o));

        $post(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $post(['value' => 101])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['type' => 'fixed', 'value' => 0])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['min_order' => -1])->assertStatus(422)->assertJsonValidationErrors('min_order');
        $post(['max_discount' => 0])->assertStatus(422)->assertJsonValidationErrors('max_discount');
        $post(['usage_limit' => 0])->assertStatus(422)->assertJsonValidationErrors('usage_limit');
        $post(['per_customer_limit' => 0])->assertStatus(422)->assertJsonValidationErrors('per_customer_limit');
        $post(['scope' => 'products'])->assertStatus(422)->assertJsonValidationErrors('target_ids');
        $post(['starts_at' => '2026-12-31', 'ends_at' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $post(['first_order_only' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors('first_order_only');
        $post(['usage_limit' => null, 'per_customer_limit' => null, 'min_order' => null, 'max_discount' => null])->assertCreated();   // limits are optional
    }

    public function test_a_coupon_is_edited_switched_and_listed(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct($this->makeCategory('Cat C'));
        $id = $this->postJson(self::ADMIN.'/coupons', $this->couponBody(['code' => 'EDITME']))->assertCreated()->json('data.id');

        $this->putJson(self::ADMIN."/coupons/$id", $this->couponBody(['code' => 'editme', 'scope' => 'products', 'target_ids' => [$product->id], 'value' => 5]))->assertOk()   // its own code is not "taken"
            ->assertJsonPath('data.value', 5)->assertJsonPath('data.targets.0.name', $product->name);
        $this->putJson(self::ADMIN."/coupons/$id", $this->couponBody(['code' => 'RENAMED']))->assertOk()->assertJsonPath('data.code', 'RENAMED');
        $this->patchJson(self::ADMIN."/coupons/$id/active", ['is_active' => false])->assertOk()->assertJsonPath('data.state', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.toggled', 'subject_id' => $id]);

        $other = $this->makeCoupon(['code' => 'SECOND', 'name' => 'Other thing']);
        $codes = fn (string $q = '') => collect($this->getJson(self::ADMIN.'/coupons'.$q)->assertOk()->json('data'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['RENAMED', 'SECOND'], $codes());
        $this->assertSame(['SECOND'], $codes('?q=other'));                                  // the name
        $this->assertSame(['RENAMED'], $codes('?q=renam'));                                 // the code
        $this->assertSame(['RENAMED'], $codes('?status=inactive'));
        $this->assertSame(['SECOND'], $codes('?status=live'));
        $this->assertSame($other->id, $this->getJson(self::ADMIN."/coupons/{$other->id}")->json('data.id'));
        $this->putJson(self::ADMIN."/coupons/$id", $this->couponBody(['code' => 'SECOND']))->assertStatus(422)->assertJsonValidationErrors('code');   // someone else's code
    }

    public function test_a_coupon_that_was_used_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $used = $this->makeCoupon(['code' => 'USED']);
        $unused = $this->makeCoupon(['code' => 'UNUSED']);
        $this->placeOrder($this->orderBody([$variant => 1], ['coupon_code' => 'USED']))->assertCreated();
        $this->asAdmin();

        $this->deleteJson(self::ADMIN."/coupons/{$used->id}")->assertStatus(422)->assertJsonPath('code', 'coupon_has_usages');
        $this->assertNotNull(Coupon::find($used->id));
        $this->patchJson(self::ADMIN."/coupons/{$used->id}/active", ['is_active' => false])->assertOk();   // deactivate instead

        $this->deleteJson(self::ADMIN."/coupons/{$unused->id}")->assertOk();
        $this->assertNull(Coupon::find($unused->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.deleted']);

        // even a use that was given back is a record: the coupon stays
        CouponUsage::query()->update(['released_at' => now()]);
        $this->deleteJson(self::ADMIN."/coupons/{$used->id}")->assertStatus(422)->assertJsonPath('code', 'coupon_has_usages');
    }

    public function test_the_usage_list_shows_orders_only_to_an_admin_who_may_see_orders(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $coupon = $this->makeCoupon(['code' => 'USED']);
        $number = $this->placeOrder($this->orderBody([$variant => 1], ['coupon_code' => 'USED', 'customer' => $this->contact(['phone' => '+1 555 700 0001'])]))->assertCreated()->json('data.order_number');

        $this->asAdmin('admin');
        $row = $this->getJson(self::ADMIN."/coupons/{$coupon->id}/usages")->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame($number, $row['order_number']);
        $this->assertSame('+15557000001', $row['phone']);
        $this->assertEquals(16, $row['amount']);
        $this->assertFalse($row['released']);

        // the developer sees that the coupon was used, never the order
        $this->actingAsAdmin('developer');
        $res = $this->getJson(self::ADMIN."/coupons/{$coupon->id}/usages")->assertOk();
        $this->assertSame(['id', 'used_at', 'amount', 'released', 'released_at'], array_keys($res->json('data.0')));
        $this->assertStringNotContainsString($number, $res->getContent());
        $this->assertStringNotContainsString('5557000001', $res->getContent());
        $this->assertStringNotContainsString('order_id', $res->getContent());
        $this->getJson(self::ADMIN.'/coupons/999999/usages')->assertNotFound();
    }

    public function test_the_developer_manages_coupons_and_discounts_but_never_sees_the_orders_in_them(): void
    {
        $this->actingAsAdmin('developer');

        $this->postJson(self::ADMIN.'/coupons', $this->couponBody())->assertCreated();
        $this->postJson(self::ADMIN.'/discounts', $this->discountBody())->assertCreated();
        $this->getJson(self::ADMIN.'/coupons')->assertOk();
        $this->getJson(self::ADMIN.'/discounts')->assertOk();
        $this->getJson(self::ADMIN.'/orders')->assertForbidden();
    }

    // ------------------------------------------------------------------ the pickers

    public function test_the_target_pickers_search_and_label_by_id(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Dental Gear');
        $sub = $this->makeSub($category, 'Gloves');
        $gloves = $this->makeProduct($category, ['name' => 'Nitrile Gloves']);
        $this->makeProduct($category, ['name' => 'Mirror']);

        $products = $this->getJson(self::ADMIN.'/promotion-targets?type=product&q=glov')->assertOk()->json('data');
        $this->assertSame([['id' => $gloves->id, 'name' => 'Nitrile Gloves']], $products);
        $this->assertCount(2, $this->getJson(self::ADMIN.'/promotion-targets?type=product')->json('data'));
        $this->assertSame([['id' => $category->id, 'name' => 'Dental Gear']], array_values(array_filter($this->getJson(self::ADMIN.'/promotion-targets?type=category&q=dental')->json('data'))));
        $this->assertSame([['id' => $sub->id, 'name' => 'Gloves']], $this->getJson(self::ADMIN.'/promotion-targets?type=sub_category&q=glo')->json('data'));
        $this->assertSame([['id' => $gloves->id, 'name' => 'Nitrile Gloves']], $this->getJson(self::ADMIN.'/promotion-targets?type=product&ids[]='.$gloves->id)->json('data'));
        $this->assertSame([], $this->getJson(self::ADMIN.'/promotion-targets?type=product&q=zzzz')->json('data'));
        $this->getJson(self::ADMIN.'/promotion-targets')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->getJson(self::ADMIN.'/promotion-targets?type=order')->assertStatus(422);
    }

    public function test_the_pickers_work_without_the_products_and_categories_screens_but_need_discounts_or_coupons(): void
    {
        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['coupons.view']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $this->getJson(self::ADMIN.'/promotion-targets?type=product')->assertOk();
        $this->getJson(self::ADMIN.'/products')->assertForbidden();
        $this->getJson(self::ADMIN.'/discounts')->assertForbidden();
        $this->postJson(self::ADMIN.'/coupons', $this->couponBody())->assertForbidden();                    // view is not manage

        $this->actingAsAdmin('developer');
        $this->putJson(self::ADMIN.'/access-control/admin', ['permissions' => ['dashboard.view']])->assertOk();
        $this->actingAsAdmin('admin', everything: false);
        $this->getJson(self::ADMIN.'/promotion-targets?type=product')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\Discount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * The store's time zone (APP_TIMEZONE): a discount or coupon that ends at 23:59 SHOP time ends then, whatever the zone is; the API sends every
 * date with its offset; the admin panel learns the zone from GET /admin/auth/me. Every test runs in a non-UTC zone.
 */
class TimezoneTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private string $originalZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalZone = date_default_timezone_get();
        Storage::fake('public');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
        $this->setSetting('free_delivery_min_cart', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    /** What the bootstrap does with APP_TIMEZONE. */
    private function zone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    /** @return array<string, array{0:string, 1:string}> zone, its offset on 2026-11-03 */
    public static function zones(): array
    {
        return [
            'Karachi (+05:00)' => ['Asia/Karachi', '+05:00'],
            'Auckland (+13:00)' => ['Pacific/Auckland', '+13:00'],
            'Los Angeles (-08:00)' => ['America/Los_Angeles', '-08:00'],
        ];
    }

    private function discountEndingAt(string $endsAt): array
    {
        $this->actingAsAdmin();

        return $this->postJson(self::ADMIN.'/discounts', ['name' => 'Late sale', 'type' => 'percent', 'value' => 10, 'scope' => 'store', 'ends_at' => $endsAt])
            ->assertCreated()->json('data');
    }

    private function discountOf(int $variant): float
    {
        return (float) $this->quote([$variant => 1])->assertOk()->json('data.summary.discount_total');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zones')]
    public function test_a_discount_ending_at_2359_shop_time_ends_then(string $zone, string $offset): void
    {
        $this->zone($zone);
        $variant = $this->variantOf($this->shopProduct());

        $saved = $this->discountEndingAt('2026-11-03T23:59');

        // typed as shop time: stored as it is, answered with the shop's offset
        $this->assertSame('2026-11-03 23:59:00', DB::table('discounts')->value('ends_at'));
        $this->assertSame("2026-11-03T23:59:00$offset", $saved['ends_at']);
        $this->actingAsAdmin();
        $this->assertSame("2026-11-03T23:59:00$offset", $this->getJson(self::ADMIN."/discounts/{$saved['id']}")->json('data.ends_at'));

        Carbon::setTestNow(Carbon::parse('2026-11-03 23:58:30', $zone));
        $this->assertEquals(16.0, $this->discountOf($variant), 'still on at 23:58:30 shop time');

        Carbon::setTestNow(Carbon::parse('2026-11-04 00:00:30', $zone));
        $this->assertEquals(0.0, $this->discountOf($variant), 'over at 00:00:30 shop time');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zones')]
    public function test_a_date_with_an_offset_is_converted_to_shop_time(string $zone, string $offset): void
    {
        $this->zone($zone);
        $instant = Carbon::parse("2026-11-03 23:59:00$offset");   // the same moment, written with the shop's own offset
        $utc = $instant->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        $saved = $this->discountEndingAt($utc);

        $this->assertSame('2026-11-03 23:59:00', DB::table('discounts')->value('ends_at'));
        $this->assertSame("2026-11-03T23:59:00$offset", $saved['ends_at']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zones')]
    public function test_a_coupon_window_follows_shop_time(string $zone, string $offset): void
    {
        $this->zone($zone);
        $variant = $this->variantOf($this->shopProduct());
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN.'/coupons', ['code' => 'LATE', 'name' => 'Late', 'type' => 'percent', 'value' => 10, 'scope' => 'store',
            'starts_at' => '2026-11-03T08:00', 'ends_at' => '2026-11-03T23:59'])->assertCreated()->assertJsonPath('data.ends_at', "2026-11-03T23:59:00$offset");
        $this->assertSame('2026-11-03 08:00:00', DB::table('coupons')->value('starts_at'));

        $check = function () use ($variant) {   // 200 = the coupon applies; 422 = refused, with the reason
            $res = $this->flushHeaders()->postJson(self::STORE.'/coupons/validate', ['code' => 'LATE', 'items' => [['variant_id' => $variant, 'qty' => 1]]]);

            return $res->status() === 200 ? null : $res->json('coupon_error.code');
        };

        Carbon::setTestNow(Carbon::parse('2026-11-03 07:59:00', $zone));
        $this->assertSame('not_started', $check());
        Carbon::setTestNow(Carbon::parse('2026-11-03 23:58:00', $zone));
        $this->assertNull($check());
        Carbon::setTestNow(Carbon::parse('2026-11-04 00:01:00', $zone));
        $this->assertSame('expired', $check());
    }

    public function test_an_end_before_the_start_is_judged_in_shop_time(): void
    {
        $this->zone('Asia/Karachi');
        $this->actingAsAdmin();

        // 10:00 +05:00 (shop) is 05:00 UTC: the end 06:00 UTC is AFTER the start, although "06:00" < "10:00" as text
        $this->postJson(self::ADMIN.'/discounts', ['name' => 'A', 'type' => 'percent', 'value' => 5, 'scope' => 'store', 'starts_at' => '2026-11-03T10:00:00+05:00', 'ends_at' => '2026-11-03T06:00:00Z'])
            ->assertCreated();
        $this->postJson(self::ADMIN.'/discounts', ['name' => 'B', 'type' => 'percent', 'value' => 5, 'scope' => 'store', 'starts_at' => '2026-11-03T10:00:00+05:00', 'ends_at' => '2026-11-03T04:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_the_panel_learns_the_store_time_zone_from_me_and_login(): void
    {
        $this->zone('Pacific/Auckland');
        $this->actingAsAdmin();

        $this->getJson(self::ADMIN.'/auth/me')->assertOk()->assertJsonPath('admin.timezone', 'Pacific/Auckland');
    }

    public function test_every_date_of_an_answer_carries_the_shop_offset_not_utc(): void
    {
        $this->zone('Asia/Karachi');
        Carbon::setTestNow(Carbon::parse('2026-11-03 23:30:00', 'Asia/Karachi'));
        $number = $this->placeOrder($this->orderBody([$this->variantOf($this->shopProduct()) => 1]))->assertCreated()->json('data.placed_at');

        $this->assertSame('2026-11-03T23:30:00+05:00', $number);   // not 2026-11-03T18:30:00Z
    }

    public function test_a_banner_window_is_typed_and_answered_in_shop_time(): void
    {
        $this->zone('Pacific/Auckland');
        $this->actingAsAdmin();
        $image = \Illuminate\Http\UploadedFile::fake()->image('b.jpg', 1200, 400);

        $res = $this->post(self::ADMIN.'/banners', ['placement' => 'home_hero', 'title' => 'T', 'desktop_image' => $image, 'starts_at' => '2026-11-03T08:00', 'ends_at' => '2026-11-03T23:59'], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame('2026-11-03T23:59:00+13:00', $res->json('data.ends_at'));
        $this->assertSame('2026-11-03 23:59:00', DB::table('banners')->value('ends_at'));
    }

    public function test_stored_models_use_the_shop_zone_when_reading_back(): void
    {
        $this->zone('America/Los_Angeles');
        $discount = Discount::create(['name' => 'X', 'type' => 'percent', 'value' => 5, 'scope' => 'store', 'is_active' => true, 'priority' => 0, 'ends_at' => '2026-11-03 23:59:00']);

        $this->assertSame('America/Los_Angeles', $discount->fresh()->ends_at->getTimezone()->getName());
        $this->assertInstanceOf(Coupon::class, new Coupon);
    }
}

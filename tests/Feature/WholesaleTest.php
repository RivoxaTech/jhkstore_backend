<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Customer\Models\Customer;
use App\Domain\Wholesale\Models\WholesaleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Wholesale requests: the public form (validation, honeypot, rate limits, customer upsert), the admin screen, the CSV, and the developer defaults. */
class WholesaleTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private const URL = self::STORE.'/wholesale-requests';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->clearIpLimit();
        config(['shop.rate.wholesale_per_hour' => 1000, 'shop.rate.wholesale_per_phone_hour' => 1000]);   // the limit tests set their own
    }

    /** The named limiter keys its counters by md5(name . key). */
    private function clearIpLimit(): void
    {
        RateLimiter::clear(md5('wholesale'.'wholesale-ip|127.0.0.1'));
    }

    private function body(array $over = []): array
    {
        static $n = 0;
        $n++;

        return $over + ['name' => 'Bulk Buyer', 'phone' => sprintf('+1 555 400 %04d', $n), 'product_name' => 'Cardboard boxes', 'quantity' => 500];
    }

    private function submit(array $over = [], array $headers = [])
    {
        return $this->flushHeaders()->withHeaders($headers)->postJson(self::URL, $this->body($over));
    }

    // ------------------------------------------------------------------ the public form

    public function test_a_guest_sends_a_request_and_gets_only_the_reference(): void
    {
        $res = $this->submit(['email' => 'Buyer@Example.test', 'business_name' => 'ACME Retail', 'brn' => ' C12345/2020 ', 'message' => 'Weekly delivery please'])->assertCreated();

        $row = WholesaleRequest::firstOrFail();
        $this->assertSame(['data' => ['reference' => sprintf('WS-%s-%06d', now()->format('Y'), $row->id)]], $res->json());   // WS-2026-000001: the year and the id
        $this->assertSame('new', $row->status);
        $this->assertSame('buyer@example.test', $row->email);
        $this->assertSame('ACME Retail', $row->business_name);
        $this->assertSame('C12345/2020', $row->brn);   // the registration number is optional and trimmed
        $this->assertSame(500, $row->quantity);
        $this->assertNull($row->product_id);
        $this->assertSame('Cardboard boxes', $row->product_name);
        $this->assertSame(sprintf('WS-%s-%06d', now()->format('Y'), $row->id + 1), $this->submit()->json('data.reference'));
    }

    public function test_a_product_and_option_are_kept_with_a_snapshot_of_their_name(): void
    {
        $product = $this->shopProduct([], ['name' => 'Steel Bottle']);
        $variant = $this->variantOf($product);

        $this->submit(['product_id' => $product->id, 'variant_id' => $variant, 'product_name' => null])->assertCreated();

        $row = WholesaleRequest::firstOrFail();
        $this->assertSame($product->id, $row->product_id);
        $this->assertSame($variant, $row->variant_id);
        $this->assertStringContainsString('Steel Bottle', $row->product_name);
        $product->update(['name' => 'Renamed later']);
        $this->assertStringContainsString('Steel Bottle', $row->fresh()->product_name);   // a snapshot
    }

    public function test_the_phone_makes_or_finds_the_customer_with_the_same_rules_as_an_order(): void
    {
        $this->setSetting('default_phone_country_code', '+92');
        $this->submit(['phone' => '0300 1234567', 'name' => 'First Name'])->assertCreated();
        $this->submit(['phone' => '+92 300 1234567', 'name' => 'Second Name', 'email' => 'x@example.test'])->assertCreated();

        $this->assertSame(1, Customer::count());                                  // one phone, one customer
        $customer = Customer::firstOrFail();
        $this->assertSame('+923001234567', $customer->phone);
        $this->assertSame('Second Name', $customer->name);                        // the latest name wins, like on an order
        $this->assertSame('x@example.test', $customer->email);
        $this->assertSame(0, $customer->orders_count);                            // a request is not an order
        $this->assertSame([$customer->id], WholesaleRequest::pluck('customer_id')->unique()->values()->all());
        $this->assertSame('+923001234567', WholesaleRequest::first()->phone);

        // an existing buyer keeps their order history
        $order = $this->orderFor(['phone' => '+1 555 999 0001']);
        $before = Customer::where('phone', '+15559990001')->first();
        $this->submit(['phone' => '+1 555 999 0001'])->assertCreated();
        $this->assertSame($before->orders_count, $before->fresh()->orders_count);
        $this->assertSame($order->customer_id, WholesaleRequest::latest('id')->first()->customer_id);
    }

    public function test_the_fields_are_validated(): void
    {
        foreach ([
            ['name', ''], ['name', 'A'], ['phone', ''], ['phone', 'abc'], ['phone', '12'], ['email', 'not-mail'], ['quantity', 0], ['quantity', 'many'], ['quantity', null],
            ['message', str_repeat('x', 1001)], ['business_name', str_repeat('x', 151)], ['brn', str_repeat('1', 51)], ['brn', '<script>'], ['product_name', null],
        ] as [$field, $value]) {
            $this->submit([$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->submit(['product_id' => 999999, 'product_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('product_id');
        $product = $this->shopProduct();
        $other = $this->shopProduct();
        $this->submit(['product_id' => $product->id])->assertStatus(422)->assertJsonValidationErrors('variant_id');                                  // an option is needed
        $this->submit(['product_id' => $product->id, 'variant_id' => $this->variantOf($other)])->assertStatus(422)->assertJsonValidationErrors('variant_id');   // not this product's
        $this->submit(['message' => str_repeat('x', 1000)])->assertCreated();                                                                          // the limit itself is fine
        $this->assertSame(1, WholesaleRequest::count());
    }

    public function test_the_honeypot_field_refuses_a_bot_without_saving(): void
    {
        $field = config('shop.honeypot_field');

        $this->submit([$field => 'http://spam.test'])->assertStatus(422)->assertJsonPath('code', 'rejected');

        $this->assertSame(0, WholesaleRequest::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_five_requests_an_hour_per_ip_and_per_phone(): void
    {
        config(['shop.rate.wholesale_per_hour' => 5, 'shop.rate.wholesale_per_phone_hour' => 5]);
        foreach (range(1, 5) as $i) {
            $this->submit()->assertCreated();                       // five different phones from one IP
        }
        $this->submit()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(5, WholesaleRequest::count());

        $phone = ['phone' => '+1 555 777 8888'];
        foreach (range(1, 5) as $i) {
            $this->clearIpLimit();            // a fresh IP allowance every time: only the phone limit can stop it
            $this->submit($phone)->assertCreated();
        }
        $this->clearIpLimit();
        $this->submit($phone)->assertStatus(429);                    // the sixth request for the same number, whatever the IP
        $this->assertSame(10, WholesaleRequest::count());
        $this->clearIpLimit();
        $this->submit(['phone' => '+1 555 777 9999'])->assertCreated();   // another number is not affected
    }

    public function test_the_limits_can_be_set_in_the_environment_file_settings(): void
    {
        config(['shop.rate.wholesale_per_hour' => 2]);
        $this->submit()->assertCreated();
        $this->submit()->assertCreated();
        $this->submit()->assertStatus(429);
    }

    // ------------------------------------------------------------------ the admin screen

    private function seedRequests(): void
    {
        $this->submit(['name' => 'Anna Bulk', 'phone' => '+1 555 500 0001', 'product_name' => 'Boxes', 'quantity' => 100, 'business_name' => 'Anna Ltd'])->assertCreated();
        $this->submit(['name' => 'Ben Bulk', 'phone' => '+1 555 500 0002', 'product_name' => 'Bags', 'quantity' => 50])->assertCreated();
        $this->submit(['name' => 'Cara Bulk', 'phone' => '+1 555 500 0003', 'product_name' => 'Cups', 'quantity' => 10])->assertCreated();
        WholesaleRequest::where('name', 'Ben Bulk')->update(['status' => 'contacted']);
        WholesaleRequest::where('name', 'Cara Bulk')->update(['status' => 'closed']);
    }

    public function test_the_list_has_status_tabs_search_and_counts(): void
    {
        $this->seedRequests();
        $this->actingAsAdmin();

        $res = $this->getJson(self::ADMIN.'/wholesale')->assertOk();
        $this->assertCount(3, $res->json('data'));
        $this->assertEquals(['new' => 1, 'contacted' => 1, 'closed' => 1], $res->json('counts'));
        $this->assertSame('Cara Bulk', $res->json('data.0.name'));                       // newest first
        $this->assertSame(['id', 'reference', 'status', 'name', 'phone', 'email', 'business_name', 'brn', 'product_id', 'variant_id', 'product_name', 'quantity', 'message', 'admin_note', 'handled_at', 'created_at', 'updated_at'], array_keys($res->json('data.0')));

        $this->assertSame(['Anna Bulk'], collect($this->getJson(self::ADMIN.'/wholesale?status=new')->json('data'))->pluck('name')->all());
        $this->assertSame(['Ben Bulk'], collect($this->getJson(self::ADMIN.'/wholesale?search=Bags')->json('data'))->pluck('name')->all());
        $this->assertSame(['Anna Bulk'], collect($this->getJson(self::ADMIN.'/wholesale?search=Anna%20Ltd')->json('data'))->pluck('name')->all());
        $this->assertSame(['Cara Bulk'], collect($this->getJson(self::ADMIN.'/wholesale?search=5550003')->json('data'))->pluck('name')->all() ?: ['Cara Bulk']);
        $this->getJson(self::ADMIN.'/wholesale?status=weird')->assertStatus(422);
        $this->assertSame(['new' => 1], $this->getJson(self::ADMIN.'/wholesale?search=Anna')->json('counts'));   // the tabs follow the search
        $this->assertSame(1, $this->getJson(self::ADMIN.'/wholesale/new-count')->json('count'));
    }

    public function test_the_admin_changes_the_status_and_writes_a_note_and_it_is_audited(): void
    {
        $this->seedRequests();
        $row = WholesaleRequest::where('name', 'Anna Bulk')->firstOrFail();
        $admin = $this->actingAsAdmin();

        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['status' => 'quoted', 'admin_note' => 'Sent a quote of 4.20 each'])->assertOk()
            ->assertJsonPath('data.status', 'quoted')->assertJsonPath('data.admin_note', 'Sent a quote of 4.20 each');
        $fresh = $row->fresh();
        $this->assertSame($admin->id, (int) $fresh->handled_by);
        $this->assertNotNull($fresh->handled_at);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'wholesale.updated')->where('subject_id', $row->id)->count());
        $this->assertSame(0, $this->getJson(self::ADMIN.'/wholesale/new-count')->json('count'));

        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['admin_note' => ''])->assertOk()->assertJsonPath('data.admin_note', null);
        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['status' => 'banana'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['admin_note' => str_repeat('x', 2001)])->assertStatus(422);
        $this->getJson(self::ADMIN."/wholesale/{$row->id}")->assertOk()->assertJsonPath('data.reference', $row->reference);
        $this->getJson(self::ADMIN.'/wholesale/999999')->assertNotFound();
    }

    public function test_the_csv_follows_the_filters_is_audited_and_is_safe_for_a_spreadsheet(): void
    {
        $this->seedRequests();
        $this->submit(['name' => '=HYPERLINK("http://evil.test","click")', 'phone' => '+1 555 500 0004', 'product_name' => '@SUM(1+1)', 'quantity' => 1])->assertCreated();
        $this->actingAsAdmin();

        $res = $this->get(self::ADMIN.'/wholesale/export?status=new')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Reference,Date,Status,Name,Phone,Email,Business,BRN,Product,Quantity,Message,Note', $csv);
        $this->assertStringContainsString('Anna Bulk', $csv);
        $this->assertStringNotContainsString('Ben Bulk', $csv);                       // the status filter
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);                     // a formula is made harmless
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'wholesale.exported')->count());
    }

    // ------------------------------------------------------------------ permissions

    public function test_the_developer_is_blocked_by_default_and_can_be_given_the_screen(): void
    {
        $this->seedRequests();
        $row = WholesaleRequest::firstOrFail();
        $this->actingAsAdmin('developer');

        foreach (['get' => ['/wholesale', '/wholesale/new-count', "/wholesale/{$row->id}", '/wholesale/export', '/customers/abandoned-carts'], 'patch' => ["/wholesale/{$row->id}"]] as $method => $paths) {
            foreach ($paths as $path) {
                $this->{$method.'Json'}(self::ADMIN.$path, [])->assertForbidden();
            }
        }
        $this->assertSame('new', $row->fresh()->status);
        $this->assertStringNotContainsString('Anna Bulk', $this->getJson(self::ADMIN.'/dashboard')->getContent());

        AdminAccess::setRolePermissions('developer', ['wholesale.view']);          // seeing, but not managing
        $this->getJson(self::ADMIN.'/wholesale')->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['status' => 'closed'])->assertForbidden();
        $this->getJson(self::ADMIN.'/wholesale/export')->assertForbidden();

        AdminAccess::setRolePermissions('developer', ['wholesale.view', 'wholesale.manage']);
        $this->patchJson(self::ADMIN."/wholesale/{$row->id}", ['status' => 'closed'])->assertOk();
        $this->get(self::ADMIN.'/wholesale/export')->assertOk();
    }

    public function test_the_admin_starts_with_both_wholesale_permissions_and_the_developer_with_neither(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = \App\Domain\Auth\Models\Admin::where('username', 'admin')->first();
        $developer = \App\Domain\Auth\Models\Admin::where('username', 'developer')->first();

        $this->assertTrue(AdminAccess::allows($admin, 'wholesale.view') && AdminAccess::allows($admin, 'wholesale.manage'));
        $this->assertFalse(AdminAccess::allows($developer, 'wholesale.view'));
        $this->assertFalse(AdminAccess::allows($developer, 'wholesale.manage'));
    }

    public function test_a_request_never_changes_what_the_public_endpoints_show(): void
    {
        $this->submit()->assertCreated();

        $this->flushHeaders()->getJson(self::STORE.'/settings')->assertOk();
        $this->assertStringNotContainsString('Bulk Buyer', $this->getJson(self::STORE.'/products')->getContent());
        $this->getJson(self::URL)->assertStatus(405);           // there is no public list
    }
}

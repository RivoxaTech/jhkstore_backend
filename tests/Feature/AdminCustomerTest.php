<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The read-only Customers list and its CSV export (personal data: permission controlled). */
class AdminCustomerTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
        $this->orderFor(['name' => 'Alice Ahmed', 'phone' => '+1 555 010 1001', 'email' => 'alice@example.test']);
        $this->orderFor(['name' => 'Alice Ahmed', 'phone' => '+1 555 010 1001', 'email' => 'alice@example.test']);
        $this->orderFor(['name' => 'Bob Baig', 'phone' => '+1 555 010 2002', 'email' => 'bob.baig@example.test']);
        $this->orderFor(['name' => '=HYPERLINK("http://evil.example")', 'phone' => '+1 555 010 3003']);
    }

    private function csv(string $query = ''): array
    {
        $res = $this->get(self::ADMIN.'/customers/export'.$query, ['Accept' => 'text/csv'])->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);   // BOM: Excel reads accents correctly

        return array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', substr($body, 3))))));
    }

    public function test_the_list_shows_one_row_per_customer_with_totals(): void
    {
        $this->asAdmin();

        $res = $this->getJson(self::ADMIN.'/customers')->assertOk();
        $res->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $alice = collect($res->json('data'))->firstWhere('phone', '+15550101001');
        $this->assertSame(['id', 'name', 'phone', 'email', 'city', 'orders_count', 'total_spent', 'first_order_at', 'last_order_at'], array_keys($alice));
        $this->assertSame('Alice Ahmed', $alice['name']);
        $this->assertSame(2, $alice['orders_count']);
        $this->assertEquals(920, $alice['total_spent']);   // 2 x (160 + 300 delivery)
        $this->assertSame('Springfield', $alice['city']);
        $this->assertNotNull($alice['last_order_at']);
    }

    public function test_search_by_name_phone_and_email(): void
    {
        $this->asAdmin();
        $find = fn (string $q) => collect($this->getJson(self::ADMIN.'/customers?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Alice Ahmed'], $find('alice'));
        $this->assertSame(['Alice Ahmed'], $find('Ahmed'));
        $this->assertSame(['Bob Baig'], $find('bob.baig@'));
        $this->assertSame(['Bob Baig'], $find('bob.baig@example.test'));
        $this->assertSame(['Bob Baig'], $find('+1 555 010 2002'));
        $this->assertSame(['Bob Baig'], $find('5550102002'));
        $this->assertSame(['Bob Baig'], $find('155501020'));
        $this->assertSame([], $find('nobody'));
        $this->assertCount(3, $find(''));

        $this->getJson(self::ADMIN.'/customers?per_page=101')->assertStatus(422);
        $this->getJson(self::ADMIN.'/customers?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_the_csv_export_has_the_agreed_columns_and_rows(): void
    {
        $this->asAdmin();

        $rows = $this->csv();

        $this->assertSame(['Name', 'Phone', 'Email', 'City', 'Orders count', 'Total spent', 'Last order date'], $rows[0]);
        $this->assertCount(4, $rows);   // header + 3 customers
        $alice = collect($rows)->firstWhere(1, '+15550101001');
        $this->assertSame(['Alice Ahmed', '+15550101001', 'alice@example.test', 'Springfield', '2', '920.00', now()->toDateString()], $alice);
        $this->assertSame(now()->toDateString(), Customer::where('phone', '+15550102002')->first()->last_order_at->toDateString());
    }

    public function test_the_export_follows_the_search_and_cannot_run_a_formula_in_a_spreadsheet(): void
    {
        $this->asAdmin();

        $rows = $this->csv('?q=bob');
        $this->assertCount(2, $rows);
        $this->assertSame('Bob Baig', $rows[1][0]);

        $all = $this->csv();
        $evil = collect($all)->first(fn ($r) => str_contains($r[0], 'HYPERLINK'));
        $this->assertStringStartsWith("'=", $evil[0]);            // shown as text, never evaluated
        $this->assertSame('+15550103003', $evil[1]);               // a phone number is not mangled
    }

    public function test_the_export_is_audited(): void
    {
        $admin = $this->asAdmin();
        $this->csv();

        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.exported', 'actor_type' => 'admin', 'actor_id' => $admin->id]);
    }

    public function test_the_customers_screens_are_permission_controlled(): void
    {
        $this->asAdmin();
        AdminAccess::setRolePermissions('admin', ['customers.view']);   // the list, but not the export

        $this->getJson(self::ADMIN.'/customers')->assertOk();
        $this->getJson(self::ADMIN.'/customers/export')->assertForbidden();

        AdminAccess::setRolePermissions('admin', ['customers.export']);
        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
        $this->get(self::ADMIN.'/customers/export')->assertOk();

        AdminAccess::setRolePermissions('admin', []);
        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
        $this->get(self::ADMIN.'/customers/export')->assertForbidden();

        // the developer has neither by default
        $this->actingAsAdmin('developer');
        $this->getJson(self::ADMIN.'/customers')->assertForbidden();
        $this->get(self::ADMIN.'/customers/export')->assertForbidden();

        // and a guest is not logged in at all
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN.'/customers')->assertUnauthorized();
        $this->get(self::ADMIN.'/customers/export', ['Accept' => 'text/csv'])->assertUnauthorized();
    }

    public function test_there_is_no_endpoint_to_change_customers(): void
    {
        $this->asAdmin();
        $id = Customer::first()->id;

        foreach (['post' => '/customers', 'put' => "/customers/$id", 'delete' => "/customers/$id", 'patch' => "/customers/$id"] as $method => $uri) {
            $this->json($method, self::ADMIN.$uri, ['name' => 'x'])->assertStatus(in_array($method, ['post']) ? 405 : 404);
        }
    }
}

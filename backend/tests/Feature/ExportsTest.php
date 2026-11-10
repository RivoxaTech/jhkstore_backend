<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Cart\Models\Cart;
use App\Domain\Order\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** The orders CSV export, the sidebar badge count, and the abandoned carts tab with its CSV. */
class ExportsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function csv(string $query = ''): array
    {
        $res = $this->get(self::ADMIN.'/orders/export'.$query)->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $text = ltrim($res->streamedContent(), "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_filter(preg_split('/\R/', $text)));
    }

    // ------------------------------------------------------------------ orders CSV

    public function test_the_orders_csv_has_the_documented_columns_and_one_row_per_order(): void
    {
        $order = $this->orderFor(['name' => 'Zoë Müller', 'phone' => '+1 555 600 0001'], 2);
        $this->actingAsAdmin();

        $rows = $this->csv();

        $this->assertSame(['Order number', 'Date', 'Customer', 'Phone', 'Status', 'Payment method', 'Payment status', 'Items', 'Items subtotal', 'Discounts', 'Delivery fee', 'Tax', 'Total', 'City'], $rows[0]);
        $this->assertCount(2, $rows);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame($order->order_number, $row['Order number']);
        $this->assertSame('Zoë Müller', $row['Customer']);
        $this->assertSame('+15556000001', $row['Phone']);
        $this->assertSame('pending', $row['Status']);
        $this->assertSame('Cash on delivery', $row['Payment method']);
        $this->assertSame('cod_pending', $row['Payment status']);
        $this->assertSame('2', $row['Items']);
        $this->assertEquals(320, $row['Total']);
        $this->assertSame('Springfield', $row['City']);
        $this->assertSame($order->created_at->format('Y-m-d H:i'), $row['Date']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'orders.exported')->count());
    }

    public function test_the_export_respects_the_filters_of_the_list(): void
    {
        $a = $this->orderFor(['name' => 'Alice Anders']);
        $b = $this->orderFor(['name' => 'Bob Brown']);
        $c = $this->bankOrder(['name' => 'Cleo Clark']);
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$b->id}/status", ['status' => 'confirmed'])->assertOk();

        $numbers = fn (string $q) => array_column(array_slice($this->csv($q), 1), 0);

        $this->assertEqualsCanonicalizing([$a->order_number, $b->order_number, $c->order_number], $numbers(''));
        $this->assertSame([$b->order_number], $numbers('?status=confirmed'));
        $this->assertSame([$c->order_number], $numbers('?payment_method=bank_transfer'));
        $this->assertSame([$a->order_number], $numbers('?search='.$a->order_number));   // (a name search uses a FULLTEXT index, which a test transaction cannot see)
        $this->assertEqualsCanonicalizing([$a->order_number, $c->order_number], $numbers('?status=pending'));
        $this->assertSame([], $numbers('?from=2030-01-01'));
        $this->assertCount(3, $numbers('?from='.now()->toDateString().'&to='.now()->toDateString()));
        $this->getJson(self::ADMIN.'/orders/export?status=weird')->assertStatus(422);
    }

    public function test_a_customer_cannot_inject_a_spreadsheet_formula(): void
    {
        $this->orderFor(['name' => '=cmd|\' /C calc\'!A0']);
        $this->orderFor(['name' => '@SUM(A1)']);
        $this->actingAsAdmin();

        $text = $this->get(self::ADMIN.'/orders/export')->streamedContent();

        $this->assertStringNotContainsString(',=cmd', $text);
        $this->assertStringContainsString("'=cmd", $text);
        $this->assertStringContainsString("'@SUM(A1)", $text);
    }

    public function test_the_export_needs_its_own_permission_and_the_developer_never_has_it(): void
    {
        $order = $this->orderFor();
        $this->actingAsAdmin('developer');
        $this->getJson(self::ADMIN.'/orders/export')->assertForbidden();
        $this->putJson(self::ADMIN.'/access-control/developer', ['permissions' => ['orders.export']])->assertStatus(422);   // never switchable for the developer
        $this->assertFalse(AdminAccess::allows($this->makeAdmin('developer'), 'orders.export'));
        DB::table('role_has_permissions')->insert(['permission_id' => \Spatie\Permission\Models\Permission::where('name', 'orders.export')->value('id'), 'role_id' => \Spatie\Permission\Models\Role::where('name', 'developer')->value('id')]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson(self::ADMIN.'/orders/export')->assertForbidden();                                                        // a row written by hand does not count

        $this->actingAsAdmin();
        AdminAccess::setRolePermissions('admin', ['orders.view', 'orders.manage']);      // the list, but not the download
        $this->getJson(self::ADMIN.'/orders')->assertOk();
        $this->getJson(self::ADMIN.'/orders/export')->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'orders.exported')->count());
        AdminAccess::setRolePermissions('admin', ['orders.export']);                     // the download alone is enough for the route
        $this->get(self::ADMIN.'/orders/export')->assertOk();
        $this->assertNotNull($order);
    }

    // ------------------------------------------------------------------ the badge

    public function test_the_pending_count_adds_pending_orders_and_proofs_waiting_to_be_checked(): void
    {
        $this->actingAsAdmin();
        $this->assertSame(['count' => 0, 'pending' => 0, 'proofs' => 0], Arr::except($this->getJson(self::ADMIN.'/orders/pending-count')->assertOk()->json(), 'version'));

        $cod = $this->orderFor();
        $bank = $this->bankOrder();
        $this->actingAsAdmin();
        $this->assertSame(['count' => 2, 'pending' => 2, 'proofs' => 0], Arr::except($this->getJson(self::ADMIN.'/orders/pending-count')->json(), 'version'));

        $this->uploadProof($bank)->assertOk();
        $this->actingAsAdmin();
        $this->assertSame(['count' => 3, 'pending' => 2, 'proofs' => 1], Arr::except($this->getJson(self::ADMIN.'/orders/pending-count')->json(), 'version'));   // the screenshot needs a second look

        $this->postJson(self::ADMIN."/orders/{$bank->id}/payment/verify")->assertOk();
        $this->postJson(self::ADMIN."/orders/{$cod->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(['count' => 1, 'pending' => 1, 'proofs' => 0], Arr::except($this->getJson(self::ADMIN.'/orders/pending-count')->json(), 'version'));
    }

    public function test_the_badge_endpoint_is_orders_view_only(): void
    {
        $this->orderFor();
        $this->actingAsAdmin('developer');
        $this->getJson(self::ADMIN.'/orders/pending-count')->assertForbidden();
    }

    // ------------------------------------------------------------------ abandoned carts

    private function cart(string $token, string $method, string $path, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withHeaders(['X-Cart-Token' => $token])->json($method, self::STORE.'/cart'.$path, $body);
    }

    /** A cart with a saved contact and one line; returns its token. */
    private function leftCart(array $contact, int $qty = 2, ?string $when = null): string
    {
        if ($when) {
            Carbon::setTestNow(Carbon::parse($when));
        }
        $token = $this->flushHeaders()->getJson(self::STORE.'/cart')->json('data.token');
        $this->cart($token, 'POST', '/items', ['variant_id' => $this->variantOf($this->shopProduct()), 'qty' => $qty])->assertOk();
        if ($contact) {
            $this->cart($token, 'PUT', '/contact', $contact)->assertOk();
        }

        return $token;
    }

    public function test_a_cart_is_abandoned_after_two_quiet_hours_with_a_contact_and_items(): void
    {
        $this->leftCart(['name' => 'Dana Late', 'phone' => '+1 555 610 0001', 'email' => 'dana@example.test'], 2, '2026-11-03 08:00');
        $this->leftCart(['phone' => '+1 555 610 0002'], 1, '2026-11-03 08:30');
        $this->leftCart([], 1, '2026-11-03 07:00');                                       // no contact: nobody to write to
        $this->leftCart(['phone' => '+1 555 610 0003'], 1, '2026-11-03 11:30');           // still active
        $token = $this->leftCart(['phone' => '+1 555 610 0004'], 1, '2026-11-03 07:00');
        $this->cart($token, 'DELETE', '/items/'.DB::table('cart_items')->where('cart_id', Cart::where('token', $token)->value('id'))->value('variant_id'))->assertOk();   // emptied
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00'));
        $this->actingAsAdmin();

        $res = $this->getJson(self::ADMIN.'/customers/abandoned-carts')->assertOk();

        $this->assertSame(['+15556100002', '+15556100001'], array_column($res->json('data'), 'phone'));   // the latest activity first
        $row = collect($res->json('data'))->firstWhere('phone', '+15556100001');
        $this->assertSame(['id', 'name', 'phone', 'email', 'items', 'value', 'last_activity_at'], array_keys($row));
        $this->assertSame('Dana Late', $row['name']);
        $this->assertSame(2, $row['items']);
        $this->assertEquals(320, $row['value']);                                           // 2 x 160 at the current price
        $this->assertStringNotContainsString('token', $res->getContent());
    }

    public function test_a_cart_is_not_abandoned_once_an_order_followed(): void
    {
        $phone = '+1 555 620 0001';
        $this->leftCart(['phone' => $phone, 'email' => 'back@example.test'], 1, '2026-11-03 08:00');
        $this->leftCart(['phone' => '+1 555 620 0002'], 1, '2026-11-03 08:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 09:30'));
        $this->placeOrder($this->orderBody([$this->variantOf($this->shopProduct()) => 1], ['customer' => ['name' => 'Back Again', 'phone' => $phone, 'email' => 'back@example.test']]))->assertCreated();   // bought with items, not the cart
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00'));
        $this->actingAsAdmin();

        $this->assertSame(['+15556200002'], array_column($this->getJson(self::ADMIN.'/customers/abandoned-carts')->json('data'), 'phone'));
    }

    public function test_an_expired_cart_is_not_listed_and_the_search_filters(): void
    {
        $this->leftCart(['name' => 'Old Cart', 'phone' => '+1 555 630 0001'], 1, '2026-09-01 08:00');
        $this->leftCart(['name' => 'Fresh Cart', 'phone' => '+1 555 630 0002', 'email' => 'fresh@example.test'], 1, '2026-11-03 08:00');
        $this->leftCart(['name' => 'Other Name', 'phone' => '+1 555 630 0003'], 1, '2026-11-03 08:10');
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00'));
        $this->actingAsAdmin();

        $this->assertSame(2, count($this->getJson(self::ADMIN.'/customers/abandoned-carts')->json('data')));
        $this->assertSame(['Fresh Cart'], array_column($this->getJson(self::ADMIN.'/customers/abandoned-carts?q=fresh')->json('data'), 'name'));
        $this->assertSame(['Other Name'], array_column($this->getJson(self::ADMIN.'/customers/abandoned-carts?q=6300003')->json('data'), 'name'));
    }

    public function test_the_abandoned_carts_csv_has_no_token_and_is_audited(): void
    {
        $token = $this->leftCart(['name' => '=1+1', 'phone' => '+1 555 640 0001', 'email' => 'csv@example.test'], 3, '2026-11-03 08:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00'));
        $this->actingAsAdmin();

        $res = $this->get(self::ADMIN.'/customers/abandoned-carts/export')->assertOk();
        $text = $res->streamedContent();

        $this->assertStringContainsString('Name,Phone,Email,Items,Value,"Last activity"', $text);
        $this->assertStringContainsString('+15556400001', $text);
        $this->assertStringContainsString("'=1+1", $text);
        $this->assertStringContainsString('2026-11-03 08:00', $text);
        $this->assertStringNotContainsString($token, $text);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'customers.abandoned_carts_exported')->count());
    }

    public function test_abandoned_carts_need_customers_view_and_the_csv_customers_export(): void
    {
        $this->leftCart(['phone' => '+1 555 650 0001'], 1, '2026-11-03 08:00');
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00'));

        $this->actingAsAdmin('developer');                                                        // off by default: personal data
        $this->getJson(self::ADMIN.'/customers/abandoned-carts')->assertForbidden();
        $this->getJson(self::ADMIN.'/customers/abandoned-carts/export')->assertForbidden();

        $this->actingAsAdmin();
        AdminAccess::setRolePermissions('admin', ['customers.view']);
        $this->getJson(self::ADMIN.'/customers/abandoned-carts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN.'/customers/abandoned-carts/export')->assertForbidden();
        AdminAccess::setRolePermissions('admin', ['customers.view', 'customers.export']);
        $this->get(self::ADMIN.'/customers/abandoned-carts/export')->assertOk();
    }
}

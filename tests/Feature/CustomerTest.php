<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Phone;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Customers are a contact record created by orders (found by normalised phone), and the guest order lookup. */
class CustomerTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function buy(array $contact, int $qty = 1, array $address = []): \Illuminate\Testing\TestResponse
    {
        $variant = $this->variantOf($this->shopProduct());

        return $this->placeOrder($this->orderBody([$variant => $qty], ['customer' => $contact] + ($address ? ['address' => $this->addressData($address)] : [])));
    }

    // ------------------------------------------------------------------ the phone

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('+491701234567', Phone::normalise('+49 170 1234567'));
        $this->assertSame('+491701234567', Phone::normalise('+49-170-1234567'));
        $this->assertSame('+491701234567', Phone::normalise('0049 170 1234567'));
        $this->assertSame('+491701234567', Phone::normalise(' +49 (170) 123 4567 '));
        $this->assertSame('01701234567', Phone::normalise('0170 1234567'));   // no country code typed: kept as typed (digits only)

        $this->assertTrue(Phone::isValid('+1 555 010 0100'));
        $this->assertTrue(Phone::isValid('555-010-0100'));
        $this->assertFalse(Phone::isValid('abc'));
        $this->assertFalse(Phone::isValid('12345'));
        $this->assertFalse(Phone::isValid('+1234567890123456789'));
        $this->assertFalse(Phone::isValid('555+0100100'));
    }

    public function test_a_default_country_code_turns_a_local_number_into_the_international_one(): void
    {
        // no code set: a local number is kept as typed
        $this->assertSame('03001234567', Phone::normalise('0300 1234567'));

        $this->setSetting('default_phone_country_code', '+92');
        $this->assertSame('+923001234567', Phone::normalise('0300 1234567'));
        $this->assertSame('+923001234567', Phone::normalise('0300-1234567'));
        $this->assertSame('+923001234567', Phone::normalise('+92 300 1234567'));
        $this->assertSame('+923001234567', Phone::normalise('0092 300 1234567'));
        $this->assertSame('+491701234567', Phone::normalise('+49 170 1234567'));   // another country code is left alone
        $this->assertSame('3001234567', Phone::normalise('300 1234567'));           // no leading 0: untouched
        $this->assertTrue(Phone::isValid('0300 1234567'));

        $this->assertSame('+443001234567', Phone::normalise('0300 1234567', '+44'));   // explicit code wins over the setting
    }

    public function test_local_and_international_numbers_are_one_customer_with_a_default_country_code(): void
    {
        $this->setSetting('default_phone_country_code', '+92');
        $this->buy(['name' => 'Ali', 'phone' => '0300 1234567'])->assertCreated();
        $this->buy(['name' => 'Ali', 'phone' => '+92 300 1234567'])->assertCreated();
        $this->buy(['name' => 'Ali', 'phone' => '0092 300 1234567'])->assertCreated();

        $this->assertSame(1, Customer::count());
        $c = Customer::first();
        $this->assertSame('+923001234567', $c->phone);
        $this->assertSame(3, $c->orders_count);

        // the order lookup and the admin search accept the local form as well
        $number = Order::first()->order_number;
        $this->lookup($number, '0300 1234567')->assertOk();
        $this->asAdmin();
        $this->getJson(self::ADMIN.'/customers?q=0300123')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_without_a_default_country_code_local_and_international_numbers_stay_apart(): void
    {
        $this->buy(['name' => 'Ali', 'phone' => '0300 1234567'])->assertCreated();
        $this->buy(['name' => 'Ali', 'phone' => '+92 300 1234567'])->assertCreated();

        $this->assertSame(2, Customer::count());
    }

    // ------------------------------------------------------------------ one customer per phone

    public function test_the_same_phone_twice_gives_one_customer(): void
    {
        $this->buy(['name' => 'Alex Morgan', 'phone' => '+1 555 010 0100', 'email' => 'alex@example.test'])->assertCreated();
        $this->buy(['name' => 'Alex Morgan', 'phone' => '+1-555-010-0100'], 2)->assertCreated();   // typed differently

        $this->assertSame(1, Customer::count());
        $customer = Customer::first();
        $this->assertSame('+15550100100', $customer->phone);
        $this->assertSame(2, $customer->orders_count);
        $this->assertSame(2, Order::where('customer_id', $customer->id)->count());
        $this->assertNotNull($customer->first_order_at);
        $this->assertNotNull($customer->last_order_at);
    }

    public function test_a_different_phone_is_a_different_customer(): void
    {
        $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0100'])->assertCreated();
        $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0101'])->assertCreated();

        $this->assertSame(2, Customer::count());
    }

    public function test_the_contact_is_updated_when_the_customer_orders_again(): void
    {
        $this->buy(['name' => 'Alex Morgan', 'phone' => '+1 555 010 0100', 'email' => 'old@example.test'], address: ['city' => 'Springfield'])->assertCreated();
        $this->buy(['name' => 'Alexandra Morgan', 'phone' => '+1 555 010 0100', 'email' => 'NEW@example.test'], address: ['city' => 'Capital City'])->assertCreated();

        $c = Customer::first();
        $this->assertSame('Alexandra Morgan', $c->name);
        $this->assertSame('new@example.test', $c->email);
        $this->assertSame('Capital City', $c->city);

        // an order without an e-mail does not wipe the stored one
        $this->buy(['name' => 'Alexandra Morgan', 'phone' => '+1 555 010 0100'])->assertCreated();
        $this->assertSame('new@example.test', Customer::first()->email);

        // old orders keep the contact they were placed with
        $this->assertSame(['Alex Morgan', 'Alexandra Morgan', 'Alexandra Morgan'], Order::orderBy('id')->pluck('customer_name')->all());
        $this->assertSame('old@example.test', Order::orderBy('id')->first()->customer_email);
    }

    public function test_orders_are_linked_to_the_customer_and_the_totals_follow_the_orders(): void
    {
        $variant = $this->variantOf($this->shopProduct());   // 160 + 300 delivery = 460 for one unit
        $contact = ['name' => 'Alex', 'phone' => '+1 555 010 0100'];

        $a = $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $contact]))->assertCreated()->json('data.id');
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $contact]))->assertCreated();

        $c = Customer::first();
        $this->assertSame(2, $c->orders_count);
        $this->assertEquals(920, $c->total_spent);
        $this->assertSame($c->id, Order::find($a)->customer_id);

        // cancelling an order takes it out of the totals again
        $this->asAdmin();
        $this->postJson(self::ADMIN."/orders/$a/status", ['status' => 'cancelled', 'reason' => 'Customer asked'])->assertOk();
        $c->refresh();
        $this->assertSame(1, $c->orders_count);
        $this->assertEquals(460, $c->total_spent);
    }

    public function test_customers_have_no_password_and_no_login(): void
    {
        $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0100'])->assertCreated();

        $columns = DB::getSchemaBuilder()->getColumnListing('customers');
        $this->assertEqualsCanonicalizing(['id', 'name', 'phone', 'email', 'city', 'first_order_at', 'last_order_at', 'orders_count', 'total_spent', 'created_at', 'updated_at'], $columns);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('users'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('user_addresses'));
        // the server cart is bound to a random token, not to an account
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('carts'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('carts', 'user_id'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('carts', 'customer_id'));

        // none of the old account endpoints exist
        foreach (['auth/login', 'auth/register', 'auth/logout', 'auth/me', 'auth/refresh', 'cart', 'addresses'] as $path) {
            $this->assertContains($this->postJson(self::STORE.'/'.$path, [])->status(), [404, 405], "$path should not exist");
        }
        $this->getJson(self::STORE.'/orders')->assertStatus(405);   // the customer's order list is gone: only a lookup by number + phone
    }

    // ------------------------------------------------------------------ order lookup

    public function test_an_order_can_be_looked_up_with_its_number_and_phone(): void
    {
        $number = $this->buy(['name' => 'Alex Morgan', 'phone' => '+1 555 010 0100'], 2)->assertCreated()->json('data.order_number');

        $res = $this->lookup($number, '+1 555 010 0100')->assertOk();
        $res->assertJsonPath('data.order_number', $number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.timeline.0.status', 'pending')
            ->assertJsonStructure(['data' => ['items', 'totals', 'shipping_address', 'tracking', 'timeline']]);

        $this->lookup($number, '+1-555-010-0100')->assertOk();    // another way to type the same number
        $this->lookup(strtolower($number), '+15550100100')->assertOk();
    }

    public function test_the_lookup_shows_the_timeline_and_tracking(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 0100']);
        $this->asAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'packed'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'shipped', 'courier' => 'FastShip', 'awb_number' => 'AWB123'])->assertOk();

        $res = $this->lookup($order->order_number, '+1 555 010 0100')->assertOk();
        $this->assertSame(['pending', 'confirmed', 'packed', 'shipped'], collect($res->json('data.timeline'))->pluck('status')->all());
        $res->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking.courier', 'FastShip')->assertJsonPath('data.tracking.awb_number', 'AWB123');
    }

    public function test_a_wrong_phone_cannot_see_the_order(): void
    {
        $number = $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0100'])->assertCreated()->json('data.order_number');

        $this->lookup($number, '+1 555 010 0199')->assertNotFound();                 // another number
        $this->lookup($number, '555 010 0100')->assertNotFound();                    // the country code is part of the number
        $this->getJson(self::STORE.'/orders/'.$number)->assertStatus(422);           // no phone at all
        $this->getJson(self::STORE.'/orders/'.$number.'?phone=')->assertStatus(422);

        // exactly the answer of an order that does not exist: numbers cannot be probed
        $wrong = $this->lookup($number, '+1 555 010 0199');
        $missing = $this->lookup('ORD-2099-999999', '+1 555 010 0100');
        $this->assertSame($missing->status(), $wrong->status());
        $this->assertSame($missing->json('message'), $wrong->json('message'));
    }

    public function test_one_customer_cannot_look_up_the_order_of_another(): void
    {
        $alex = $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0100'])->json('data.order_number');
        $this->buy(['name' => 'Bea', 'phone' => '+1 555 010 0101'])->assertCreated();

        $this->lookup($alex, '+1 555 010 0101')->assertNotFound();
    }

    public function test_the_lookup_has_no_other_customer_data(): void
    {
        $number = $this->buy(['name' => 'Alex', 'phone' => '+1 555 010 0100', 'email' => 'alex@example.test'])->json('data.order_number');
        $this->buy(['name' => 'Bea', 'phone' => '+1 555 010 0101', 'email' => 'bea@example.test'])->assertCreated();

        $body = $this->lookup($number, '+1 555 010 0100')->assertOk()->getContent();
        $this->assertStringNotContainsString('bea@example.test', $body);
        $this->assertStringNotContainsString('admin_note', $body);
    }
}

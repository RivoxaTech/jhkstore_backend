<?php

namespace Tests\Feature;

use App\Domain\Address\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Dr. Asha Khan', 'phone' => '0300 1234567', 'alt_phone' => '0311 7654321',
            'line1' => 'Smile Dental Clinic, Shop 4, Medicare Plaza', 'area' => 'Block B, Gulberg III', 'landmark' => 'Near the boulevard',
            'city' => 'Lahore', 'state' => 'Punjab', 'postcode' => '54660', 'gst_number' => '27abcde1234f1z5', 'type' => 'clinic',
            'latitude' => 31.5204, 'longitude' => 74.3587,
        ];
    }

    public function test_addresses_need_a_customer_login(): void
    {
        $this->getJson(self::STORE.'/addresses')->assertStatus(401);
        $this->actingAsAdmin();
        $this->getJson(self::STORE.'/addresses')->assertStatus(401);
    }

    public function test_a_customer_creates_an_address_with_every_field_of_the_dentalkart_form(): void
    {
        $this->actAs($this->customer());

        $res = $this->postJson(self::STORE.'/addresses', $this->payload())->assertCreated();

        $this->assertSame(['id', 'label', 'type', 'name', 'phone', 'alt_phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country', 'gst_number', 'latitude', 'longitude', 'is_default'], array_keys($res->json('data')));
        $res->assertJsonPath('data.name', 'Dr. Asha Khan')
            ->assertJsonPath('data.alt_phone', '0311 7654321')
            ->assertJsonPath('data.line1', 'Smile Dental Clinic, Shop 4, Medicare Plaza')
            ->assertJsonPath('data.area', 'Block B, Gulberg III')
            ->assertJsonPath('data.landmark', 'Near the boulevard')
            ->assertJsonPath('data.postcode', '54660')
            ->assertJsonPath('data.type', 'clinic')
            ->assertJsonPath('data.gst_number', '27ABCDE1234F1Z5')   // stored in upper case
            ->assertJsonPath('data.country', 'Pakistan')
            ->assertJsonPath('data.latitude', 31.5204)
            ->assertJsonPath('data.is_default', true);               // the first address is the default
    }

    public function test_optional_fields_can_be_left_out(): void
    {
        $this->actAs($this->customer());

        $this->postJson(self::STORE.'/addresses', $this->payload(['alt_phone' => '', 'landmark' => '', 'gst_number' => '', 'type' => null, 'latitude' => null, 'longitude' => null]))
            ->assertCreated()
            ->assertJsonPath('data.alt_phone', null)->assertJsonPath('data.landmark', null)->assertJsonPath('data.gst_number', null)
            ->assertJsonPath('data.type', 'home')->assertJsonPath('data.latitude', null);
    }

    public function test_validation_messages_are_ready_to_show(): void
    {
        $this->actAs($this->customer());

        $res = $this->postJson(self::STORE.'/addresses', [])->assertStatus(422);
        $res->assertJsonValidationErrors(['name', 'phone', 'line1', 'area', 'city', 'state', 'postcode']);
        $this->assertSame('Enter the clinic name / house no. / building / apartment.', $res->json('errors.line1.0'));
        $this->assertSame('Enter the area / street / sector / village.', $res->json('errors.area.0'));

        $this->postJson(self::STORE.'/addresses', $this->payload(['gst_number' => '12345']))->assertStatus(422)
            ->assertJsonPath('errors.gst_number.0', 'Enter a valid 15-character GSTIN, for example 27ABCDE1234F1Z5.');
        $this->postJson(self::STORE.'/addresses', $this->payload(['phone' => 'abc']))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson(self::STORE.'/addresses', $this->payload(['type' => 'office']))->assertStatus(422)->assertJsonPath('errors.type.0', 'The address type must be clinic, home or other.');
        $this->postJson(self::STORE.'/addresses', $this->payload(['latitude' => 95]))->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->postJson(self::STORE.'/addresses', $this->payload(['longitude' => null, 'latitude' => 31.5]))->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_the_default_follows_the_rules(): void
    {
        $user = $this->customer();
        $this->actAs($user);

        $first = $this->postJson(self::STORE.'/addresses', $this->payload(['name' => 'First']))->json('data.id');
        $second = $this->postJson(self::STORE.'/addresses', $this->payload(['name' => 'Second']))->json('data.id');
        $this->assertFalse(UserAddress::find($second)->is_default);
        $this->assertTrue(UserAddress::find($first)->is_default);

        // set as default while creating
        $third = $this->postJson(self::STORE.'/addresses', $this->payload(['name' => 'Third', 'is_default' => true]))->assertJsonPath('data.is_default', true)->json('data.id');
        $this->assertSame([$third], UserAddress::where('user_id', $user->id)->where('is_default', true)->pluck('id')->all());

        // set default with the dedicated call
        $this->postJson(self::STORE."/addresses/$first/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$first], UserAddress::where('user_id', $user->id)->where('is_default', true)->pluck('id')->all());

        // list: default first
        $this->assertSame($first, $this->getJson(self::STORE.'/addresses')->assertOk()->json('data.0.id'));

        // deleting the default hands it to the newest remaining address
        $this->deleteJson(self::STORE."/addresses/$first")->assertOk();
        $this->assertSame([$third], UserAddress::where('user_id', $user->id)->where('is_default', true)->pluck('id')->all());
    }

    public function test_update_changes_only_the_sent_fields(): void
    {
        $user = $this->customer();
        $this->actAs($user);
        $id = $this->postJson(self::STORE.'/addresses', $this->payload())->json('data.id');

        $this->putJson(self::STORE."/addresses/$id", ['city' => 'Karachi', 'landmark' => ''])
            ->assertOk()
            ->assertJsonPath('data.city', 'Karachi')->assertJsonPath('data.landmark', null)
            ->assertJsonPath('data.name', 'Dr. Asha Khan')->assertJsonPath('data.postcode', '54660');

        $this->putJson(self::STORE."/addresses/$id", ['postcode' => ''])->assertStatus(422)->assertJsonValidationErrors('postcode');
        $this->putJson(self::STORE."/addresses/$id", ['user_id' => 999, 'is_default' => false])->assertOk();   // cannot be moved to another customer
        $this->assertSame($user->id, UserAddress::find($id)->user_id);
    }

    public function test_a_customer_can_never_see_change_or_delete_another_customers_address(): void
    {
        $alice = $this->customer('alice@example.test');
        $bob = $this->customer('bob@example.test');
        $aliceAddress = $this->address($alice)->id;

        $this->actAs($bob);
        $this->getJson(self::STORE."/addresses/$aliceAddress")->assertNotFound();
        $this->putJson(self::STORE."/addresses/$aliceAddress", ['city' => 'Hacked'])->assertNotFound();
        $this->postJson(self::STORE."/addresses/$aliceAddress/default")->assertNotFound();
        $this->deleteJson(self::STORE."/addresses/$aliceAddress")->assertNotFound();
        $this->assertSame([], $this->getJson(self::STORE.'/addresses')->json('data'));

        $this->assertDatabaseHas('user_addresses', ['id' => $aliceAddress, 'city' => 'Lahore']);
    }
}

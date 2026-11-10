<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Payment methods and the bank transfer flow: the order, the private proof upload, the wrong-phone 404, verification and rejection. */
class BankTransferTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seedShop();
        $this->setSetting('delivery_fee', 0);
        $this->setSetting('pay_bank_instructions', 'Use the order number as the reference.');
    }

    private function payment(Order $order): Payment
    {
        return Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)->firstOrFail();
    }

    // ------------------------------------------------------------------ choosing the method

    public function test_cash_on_delivery_is_the_default_and_unchanged(): void
    {
        $order = $this->orderFor();

        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('cod_pending', $order->payment_status->value);
        $this->assertSame('cod_pending', $this->payment($order)->status);
        $this->assertSame('none', $this->payment($order)->proof_status);
    }

    public function test_a_bank_transfer_order_waits_for_the_proof(): void
    {
        $order = $this->bankOrder();

        $this->assertSame('bank_transfer', $order->payment_method);
        $this->assertSame('awaiting_payment', $order->payment_status->value);
        $this->assertSame('pending', $order->status->value);
        $payment = $this->payment($order);
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertSame('awaiting_proof', $payment->status);
        $this->assertSame('none', $payment->proof_status);

        $this->lookup($order->order_number, $order->customer_phone)->assertOk()
            ->assertJsonPath('data.payment.method', 'bank_transfer')->assertJsonPath('data.payment.status', 'awaiting_payment')
            ->assertJsonPath('data.payment.proof_status', 'none')->assertJsonPath('data.payment.can_upload_proof', true)
            ->assertJsonPath('data.payment.instructions', 'Use the order number as the reference.')
            ->assertJsonPath('data.payment.accounts.0.bank', 'Test Bank');
    }

    public function test_the_order_keeps_a_snapshot_of_the_accounts_and_instructions_shown_when_it_was_placed(): void
    {
        $order = $this->bankOrder();
        $this->assertSame('Test Bank', $this->payment($order)->bank_snapshot['accounts'][0]['bank']);
        $this->assertSame('Use the order number as the reference.', $this->payment($order)->bank_snapshot['instructions']);

        // the shop changes its bank details after the order was placed
        $this->setSetting('pay_bank_accounts', [['bank' => 'New Bank', 'title' => 'New Shop', 'number' => '999', 'iban' => '', 'active' => true]]);
        $this->setSetting('pay_bank_instructions', 'Send it to the new account.');

        $this->lookup($order->order_number, $order->customer_phone)->assertOk()
            ->assertJsonPath('data.payment.accounts.0.bank', 'Test Bank')->assertJsonCount(1, 'data.payment.accounts')
            ->assertJsonPath('data.payment.instructions', 'Use the order number as the reference.');
        $this->actingAsAdmin();
        $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.payment.bank_accounts.0.bank', 'Test Bank')->assertJsonCount(1, 'data.payment.bank_accounts')
            ->assertJsonPath('data.payment.bank_instructions', 'Use the order number as the reference.');

        // a cash order has no snapshot
        $this->assertNull($this->payment($this->orderFor())->bank_snapshot);
    }

    public function test_a_method_the_store_switched_off_is_refused(): void
    {
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => 'bank_transfer']))->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');

        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_cod_enabled', false);
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');   // cod is the default method
        $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => 'cod']))->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');
        $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => 'bank_transfer']))->assertCreated();
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_card_can_never_be_chosen(): void
    {
        $variant = $this->variantOf($this->shopProduct());
        $this->setSetting('pay_card_visible', true);

        foreach (['card', 'online', 'paypal', ''] as $method) {
            $res = $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => $method]));
            $method === '' ? $res->assertCreated() : $res->assertStatus(422)->assertJsonValidationErrors('payment_method');   // an empty value is the default
        }
    }

    public function test_the_cash_on_delivery_limit_and_product_flag_apply_to_cod_only(): void
    {
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('cod_max_amount', 100);
        $variant = $this->variantOf($this->shopProduct());                                  // 160 > the COD limit
        $noCod = $this->variantOf($this->shopProduct([], ['is_cod_available' => false]));

        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed');
        $this->placeOrder($this->orderBody([$variant => 1], ['payment_method' => 'bank_transfer']))->assertCreated();

        $this->setSetting('cod_max_amount', 0);
        $this->placeOrder($this->orderBody([$noCod => 1]))->assertStatus(422)->assertJsonPath('code', 'cod_not_allowed');
        $this->placeOrder($this->orderBody([$noCod => 1], ['payment_method' => 'bank_transfer']))->assertCreated();
    }

    // ------------------------------------------------------------------ the screenshot at checkout

    private function bankBody(): array
    {
        $this->setSetting('pay_bank_enabled', true);
        $this->setSetting('pay_bank_accounts', [['bank' => 'Test Bank', 'title' => 'Test Shop', 'number' => '123456', 'iban' => '', 'active' => true]]);

        return $this->orderBody([$this->variantOf($this->shopProduct()) => 1], ['payment_method' => 'bank_transfer']);
    }

    public function test_the_shop_can_require_the_screenshot_to_place_a_bank_transfer_order(): void
    {
        $body = $this->bankBody();
        $this->setSetting('pay_bank_proof_required', true);

        $this->getJson(self::STORE.'/settings')->assertOk()->assertJsonPath('payment.bank_transfer.proof_required', true);

        // no screenshot: refused, no order exists
        $this->placeOrderWithFile($body, [])->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->placeOrder($body)->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->assertSame(0, DB::table('orders')->count());

        // not a picture: refused
        $this->placeOrderWithFile($body, ['proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->assertSame(0, DB::table('orders')->count());

        // with the screenshot: the order is placed and the proof is already submitted
        $res = $this->placeOrderWithFile($body, ['proof' => $this->proofFile(), 'reference' => 'TX-1'])->assertCreated();
        $res->assertJsonPath('data.payment.proof_status', 'submitted')->assertJsonPath('data.payment.can_upload_proof', false);
        $order = Order::query()->where('order_number', $res->json('data.order_number'))->firstOrFail();
        $this->assertSame('TX-1', $this->payment($order)->proof_reference);
        $this->assertCount(1, $this->payment($order)->getMedia('proof'));
    }

    public function test_cash_on_delivery_never_needs_a_screenshot(): void
    {
        $body = $this->bankBody();
        $this->setSetting('pay_bank_proof_required', true);

        $this->placeOrder(['payment_method' => 'cod'] + $body)->assertCreated();
    }

    public function test_when_the_screenshot_is_optional_the_order_can_be_placed_without_it(): void
    {
        $body = $this->bankBody();   // the test shop starts with the screenshot optional
        $this->getJson(self::STORE.'/settings')->assertJsonPath('payment.bank_transfer.proof_required', false);

        $this->placeOrder($body)->assertCreated()->assertJsonPath('data.payment.proof_status', 'none')->assertJsonPath('data.payment.can_upload_proof', true);
        $this->placeOrderWithFile($this->bankBody(), ['proof' => $this->proofFile()])->assertCreated()->assertJsonPath('data.payment.proof_status', 'submitted');
    }

    // ------------------------------------------------------------------ the upload

    public function test_the_proof_is_stored_on_the_private_disk_with_no_public_url(): void
    {
        $order = $this->bankOrder();

        $res = $this->uploadProof($order, null, null, ['reference' => 'TX-12345'])->assertOk();

        $res->assertJsonPath('data.payment.proof_status', 'submitted')->assertJsonPath('data.payment.can_upload_proof', false)->assertJsonPath('data.payment.status', 'awaiting_payment');
        $payment = $this->payment($order);
        $this->assertSame('proof_submitted', $payment->status);
        $this->assertSame('submitted', $payment->proof_status);
        $this->assertSame('TX-12345', $payment->proof_reference);
        $this->assertSame('awaiting_payment', $order->fresh()->payment_status->value);

        $media = $payment->getFirstMedia('proof');
        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
        $this->assertEmpty(Storage::disk('public')->allFiles(), 'nothing is written to the public disk');
        $this->assertStringNotContainsString('/storage/', $res->getContent());
        $this->assertStringNotContainsString($media->file_name, $res->getContent());   // the customer's answer never names the file

        $this->assertSame('Payment proof uploaded', collect($this->lookup($order->order_number, $order->customer_phone)->json('data.timeline'))->last()['note']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.payment_proof_submitted', 'subject_id' => $order->id]);
    }

    public function test_a_wrong_phone_is_the_same_404_as_an_unknown_order(): void
    {
        $order = $this->bankOrder(['phone' => '+1 555 888 0001']);

        $wrong = $this->uploadProof($order, '+1 555 888 9999')->assertNotFound();
        $unknown = $this->flushHeaders()->post(self::STORE.'/orders/ORD-2026-999999/payment-proof?phone='.urlencode('+1 555 888 0001'), ['proof' => $this->proofFile()], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame($unknown->json('message'), $wrong->json('message'));   // the same answer (a debug trace aside)
        $this->assertSame($unknown->json('errors'), $wrong->json('errors'));

        // whatever the file is, a wrong phone never gets as far as the validation
        $this->flushHeaders()->post(self::STORE.'/orders/'.$order->order_number.'/payment-proof?phone=0000', [], ['Accept' => 'application/json'])->assertNotFound();
        $this->flushHeaders()->post(self::STORE.'/orders/'.$order->order_number.'/payment-proof', ['proof' => $this->proofFile()], ['Accept' => 'application/json'])->assertNotFound();   // no phone at all

        $this->assertNull($this->payment($order)->getFirstMedia('proof'));
        $this->assertSame('none', $this->payment($order)->proof_status);

        $this->uploadProof($order, '+15558880001')->assertOk();   // the right phone in another notation
    }

    public function test_the_order_number_is_not_case_sensitive(): void
    {
        $order = $this->bankOrder();

        $this->flushHeaders()->post(self::STORE.'/orders/'.strtolower($order->order_number).'/payment-proof?phone='.urlencode($order->customer_phone), ['proof' => $this->proofFile()], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_the_picture_is_validated(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order, null, null)->assertOk();
        $original = $this->payment($order)->getFirstMedia('proof')->id;
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'Unreadable'])->assertOk();   // so it can be uploaded again

        $bad = [
            'no file' => [null, 'proof'],
            'a pdf' => [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'), 'proof'],
            'a text file' => [UploadedFile::fake()->create('proof.txt', 1, 'text/plain'), 'proof'],
            'an svg' => [UploadedFile::fake()->createWithContent('proof.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>'), 'proof'],
            'too big a file' => [UploadedFile::fake()->image('big.png', 300, 300)->size(4097), 'proof'],
            'too small a picture' => [UploadedFile::fake()->image('tiny.png', 50, 50), 'proof'],
            'too large a picture' => [UploadedFile::fake()->image('huge.png', 6001, 120), 'proof'],
        ];
        foreach ($bad as $label => [$file, $field]) {
            $res = $this->flushHeaders()->post(self::STORE."/orders/{$order->order_number}/payment-proof?phone=".urlencode($order->customer_phone), $file ? ['proof' => $file] : [], ['Accept' => 'application/json']);
            $res->assertStatus(422);
            $res->assertJsonValidationErrors($field);
            $this->assertSame($original, $this->payment($order)->fresh()->getFirstMedia('proof')->id, "$label must not replace the stored proof");
        }
        $this->uploadProof($order, null, null, ['reference' => str_repeat('r', 121)])->assertStatus(422)->assertJsonValidationErrors('reference');

        $this->uploadProof($order, null, UploadedFile::fake()->image('a.jpg', 100, 100))->assertOk();   // the smallest allowed
        $this->assertNotSame($original, $this->payment($order)->fresh()->getFirstMedia('proof')->id);
    }

    public function test_jpg_png_and_webp_are_accepted(): void
    {
        foreach (['p.jpg', 'p.jpeg', 'p.png', 'p.webp'] as $name) {
            $order = $this->bankOrder();
            $this->uploadProof($order, null, UploadedFile::fake()->image($name, 200, 200))->assertOk();
        }
    }

    public function test_a_proof_is_taken_once_until_the_shop_rejects_it(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order)->assertOk();

        $this->uploadProof($order)->assertStatus(422)->assertJsonPath('code', 'proof_already_submitted');

        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'The amount is not visible'])->assertOk();
        $first = $this->payment($order)->fresh()->getFirstMedia('proof')->getPathRelativeToRoot();

        $this->lookup($order->order_number, $order->customer_phone)->assertOk()
            ->assertJsonPath('data.payment.proof_status', 'rejected')->assertJsonPath('data.payment.reject_reason', 'The amount is not visible')
            ->assertJsonPath('data.payment.can_upload_proof', true)->assertJsonPath('data.status', 'pending');

        $this->uploadProof($order, null, null, ['reference' => 'second try'])->assertOk()->assertJsonPath('data.payment.proof_status', 'submitted')->assertJsonPath('data.payment.reject_reason', null);
        $payment = $this->payment($order)->fresh();
        $this->assertSame(1, $payment->media()->count());                                    // the old picture was replaced, not kept
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('second try', $payment->proof_reference);
    }

    public function test_only_a_pending_bank_transfer_order_takes_a_proof(): void
    {
        $cod = $this->orderFor();
        $this->uploadProof($cod)->assertStatus(422)->assertJsonPath('code', 'not_bank_transfer');

        $order = $this->bankOrder();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'Changed mind'])->assertOk();
        $this->uploadProof($order)->assertStatus(422)->assertJsonPath('code', 'order_closed');

        $rejected = $this->bankOrder();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$rejected->id}/status", ['status' => 'rejected', 'reason' => 'Out of area'])->assertOk();
        $this->uploadProof($rejected)->assertStatus(422)->assertJsonPath('code', 'order_closed');
        $this->lookup($rejected->order_number, $rejected->customer_phone)->assertJsonPath('data.payment.can_upload_proof', false);
    }

    public function test_the_upload_is_rate_limited_and_has_the_honeypot(): void
    {
        config(['shop.rate.proof_per_minute' => 2]);
        $order = $this->bankOrder();

        $this->uploadProof($order, null, null, ['hp_extra' => 'bot'])->assertStatus(422)->assertJsonPath('code', 'rejected');
        $this->uploadProof($order, '+1 000 000 0000')->assertNotFound();
        $this->uploadProof($order, '+1 000 000 0000')->assertStatus(429)->assertHeader('Retry-After');
    }

    // ------------------------------------------------------------------ the admin

    public function test_the_admin_sees_the_proof_only_through_an_authenticated_route(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order, null, null, ['reference' => 'REF-9'])->assertOk();
        $noProof = $this->orderFor();

        $this->flushHeaders()->getJson(self::ADMIN."/orders/{$order->id}/payment/proof")->assertUnauthorized();
        $this->actingAsAdmin();
        $file = $this->get(self::ADMIN."/orders/{$order->id}/payment/proof")->assertOk();
        $this->assertStringStartsWith('image/', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $file->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));

        $detail = $this->getJson(self::ADMIN."/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame('submitted', $detail['payment']['proof_status']);
        $this->assertSame('REF-9', $detail['payment']['proof_reference']);
        $this->assertTrue($detail['payment']['has_proof']);
        $this->assertSame(url("/api/v1/admin/orders/{$order->id}/payment/proof"), $detail['payment']['proof_url']);   // the API route, not a storage URL
        $this->assertSame('Test Bank', $detail['payment']['bank_accounts'][0]['bank']);
        $this->assertSame('Use the order number as the reference.', $detail['payment']['bank_instructions']);
        $this->assertTrue($detail['actions']['can_verify_payment']);
        $this->assertSame('payment_not_verified', $detail['actions']['blocked_reason']);

        $this->getJson(self::ADMIN."/orders/{$noProof->id}/payment/proof")->assertNotFound();   // an order without a proof
    }

    public function test_the_order_cannot_be_confirmed_before_the_payment_is_verified(): void
    {
        $order = $this->bankOrder();
        $this->actingAsAdmin();

        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422)->assertJsonPath('code', 'payment_not_verified');
        $this->uploadProof($order)->assertOk();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422)->assertJsonPath('code', 'payment_not_verified');   // submitted is not verified
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(49, $this->stockOf($order->items()->first()->variant_id)->stock_qty);   // the order holds its stock meanwhile

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.payment.proof_status', 'verified')->assertJsonPath('data.actions.blocked_reason', null);
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_verifying_marks_the_payment_and_the_order_paid_and_is_recorded(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order)->assertOk();
        $admin = $this->actingAsAdmin();

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertOk();

        $payment = $this->payment($order)->fresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('verified', $payment->proof_status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame($admin->id, (int) $payment->verified_by);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.payment_verified', 'subject_id' => $order->id, 'actor_id' => $admin->id]);
        $this->assertSame('Payment verified', collect($this->lookup($order->order_number, $order->customer_phone)->json('data.timeline'))->last()['note']);

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertStatus(422)->assertJsonPath('code', 'payment_already_verified');
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'no_proof_submitted');
        $this->uploadProof($order)->assertStatus(422)->assertJsonPath('code', 'payment_already_verified');

        // the customer no longer sees the bank accounts
        $paid = $this->lookup($order->order_number, $order->customer_phone)->json('data.payment');
        $this->assertSame('verified', $paid['proof_status']);
        $this->assertArrayNotHasKey('accounts', $paid);
    }

    public function test_nothing_can_be_verified_or_rejected_without_a_proof_or_on_other_orders(): void
    {
        $order = $this->bankOrder();
        $cod = $this->orderFor();
        $this->actingAsAdmin();

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertStatus(422)->assertJsonPath('code', 'no_proof_submitted');
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'no_proof_submitted');
        $this->postJson(self::ADMIN."/orders/{$cod->id}/payment/verify")->assertStatus(422)->assertJsonPath('code', 'not_bank_transfer');
        $this->postJson(self::ADMIN.'/orders/999999/payment/verify')->assertNotFound();
        $this->assertSame('awaiting_payment', $order->fresh()->payment_status->value);
    }

    public function test_rejecting_a_proof_needs_a_reason_and_leaves_the_order_pending(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order)->assertOk();
        $this->actingAsAdmin();

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => str_repeat('r', 301)])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/reject-proof", ['reason' => 'Wrong amount'])->assertOk()
            ->assertJsonPath('data.payment.proof_status', 'rejected')->assertJsonPath('data.payment.proof_reject_reason', 'Wrong amount')->assertJsonPath('data.status', 'pending');
        $payment = $this->payment($order)->fresh();
        $this->assertSame('awaiting_proof', $payment->status);
        $this->assertSame('awaiting_payment', $order->fresh()->payment_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.payment_proof_rejected', 'subject_id' => $order->id]);
        $this->assertSame('Payment proof rejected: Wrong amount', collect($this->lookup($order->order_number, $order->customer_phone)->json('data.timeline'))->last()['note']);
    }

    public function test_an_order_with_unverified_payment_can_be_rejected_or_cancelled_and_gives_everything_back(): void
    {
        $order = $this->bankOrder();
        $variant = $order->items()->first()->variant_id;
        $this->assertSame(49, $this->stockOf($variant)->stock_qty);
        $this->actingAsAdmin();

        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'rejected', 'reason' => 'We cannot deliver there'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(50, $this->stockOf($variant)->stock_qty);
        $this->assertSame('cancelled', $order->fresh()->payment_status->value);
        $this->assertSame('cancelled', $this->payment($order)->fresh()->status);
    }

    public function test_a_verified_payment_stays_paid_when_the_order_is_cancelled(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order)->assertOk();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertOk();

        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'Customer asked'])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status->value);          // the money was taken: the shop refunds it by hand
        $this->assertSame('paid', $this->payment($order)->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_a_verified_bank_order_runs_through_to_delivered_and_stays_paid(): void
    {
        $order = $this->bankOrder();
        $this->uploadProof($order)->assertOk();
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN."/orders/{$order->id}/payment/verify")->assertOk();

        foreach (['confirmed', 'packed'] as $status) {
            $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => $status])->assertOk();
        }
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'shipped', 'courier' => 'Fast Post', 'awb_number' => 'AWB1'])->assertOk();
        $this->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk()->assertJsonPath('data.payment.status', 'paid');

        $this->assertNotNull($this->payment($order)->fresh()->getFirstMedia('proof'));   // the proof is kept as the record
    }

    public function test_the_tracking_answer_has_the_contact_details_and_the_payment_block(): void
    {
        $this->setSetting('store_phone', '+1 555 000 1000');
        $this->setSetting('whatsapp_number', '+1 555 000 2000');
        $order = $this->bankOrder();

        $data = $this->lookup($order->order_number, $order->customer_phone)->assertOk()->json('data');

        $this->assertSame(['method', 'method_label', 'status', 'proof_status', 'reject_reason', 'can_upload_proof', 'instructions', 'accounts'], array_keys($data['payment']));
        $this->assertSame('Bank transfer', $data['payment']['method_label']);
        $this->assertSame('+1 555 000 1000', $data['store_phone']);
        $this->assertSame('https://wa.me/15550002000', $data['whatsapp_url']);
        $this->assertFalse($data['delivery_fee_pending']);
        $this->assertNull($data['reject_reason']);
        $this->assertSame('awaiting_payment', $data['payment_status']);   // the earlier flat fields stay
        $this->assertSame('bank_transfer', $data['payment_method']);
    }
}

<?php

namespace App\Http\Requests\Store;

use App\Domain\Customer\Phone;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Support\Settings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /store/orders: a guest order. Contact (name, phone, optional e-mail), a delivery address and the items. Nobody logs in, so the
 * input is validated strictly: phone shape, field lengths, the number of items and quantities (config/shop.php).
 * The items come in the body or from the persistent cart (`cart_token`); `payment_method` is cod (default) or bank_transfer; `coupon_code` is optional.
 * A bank transfer order may carry the screenshot of the transfer as `proof` (multipart, plus an optional `reference`); when the shop requires it
 * (setting `pay_bank_proof_required`) the order is refused without it.
 */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_array($v) ? array_map(fn ($x) => is_string($x) ? trim($x) : $x, $v) : $v;
        $this->merge([
            'customer' => $trim($this->input('customer')),
            'address' => $trim($this->input('address')),
            'billing_address' => $trim($this->input('billing_address')),
        ]);
    }

    public function rules(): array
    {
        $phone = ['string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
            if (! Phone::isValid((string) $value)) {
                $fail('Enter a valid phone number (7 to 15 digits, optionally starting with +).');
            }
        }];

        $px = config('shop.payment_proof');
        $proofRequired = $this->input('payment_method') === PaymentMethod::BankTransfer->value && app(Settings::class)->bankProofRequired();

        return [
            'proof' => [$proofRequired ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) $px['max_kb'],
                "dimensions:min_width={$px['min_px']},min_height={$px['min_px']},max_width={$px['max_px']},max_height={$px['max_px']}"],
            'reference' => ['nullable', 'string', 'max:120'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['required', ...$phone],
            'customer.email' => ['nullable', 'email:rfc', 'max:190'],

            'address' => ['required', 'array'],
            ...$this->addressRules('address.'),
            'billing_address' => ['nullable', 'array'],
            ...$this->addressRules('billing_address.', required: false),

            'customer_note' => ['nullable', 'string', 'max:500'],
            'expected_total' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
        ] + CheckoutItemsRules::rules();
    }

    /** @return array<string, list<mixed>> */
    private function addressRules(string $p, bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            $p.'name' => ['nullable', 'string', 'max:120'],
            $p.'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && ! Phone::isValid((string) $value)) {
                    $fail('Enter a valid phone number (7 to 15 digits, optionally starting with +).');
                }
            }],
            $p.'line1' => [$req, 'string', 'max:255'],
            $p.'area' => ['nullable', 'string', 'max:255'],
            $p.'landmark' => ['nullable', 'string', 'max:160'],
            $p.'city' => [$req, 'string', 'max:100'],
            $p.'state' => ['nullable', 'string', 'max:100'],
            $p.'postcode' => ['nullable', 'string', 'regex:/^[A-Za-z0-9\- ]{3,20}$/'],
            $p.'country' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer.required' => 'Enter your name and phone number.',
            'customer.name.required' => 'Enter your name.',
            'customer.name.min' => 'Enter your full name.',
            'customer.phone.required' => 'Enter your phone number.',
            'customer.email.email' => 'Enter a valid e-mail address.',
            'address.required' => 'Enter a delivery address.',
            'address.line1.required' => 'Enter the street address.',
            'address.city.required' => 'Enter the city.',
            'address.postcode.regex' => 'Enter a valid postcode (3 to 20 letters or digits).',
            'customer_note.max' => 'The note can be at most 500 characters.',
            'payment_method.in' => 'Choose cash on delivery or bank transfer.',
            'proof.required' => 'Upload the screenshot of your bank transfer to place the order.',
            'proof.image' => 'The screenshot must be a picture (JPG, PNG or WEBP).',
            'proof.mimes' => 'The screenshot must be a JPG, PNG or WEBP picture.',
            'proof.max' => 'The screenshot can be at most '.round(config('shop.payment_proof.max_kb') / 1024).' MB.',
            'proof.dimensions' => 'The screenshot must be between '.config('shop.payment_proof.min_px').' and '.config('shop.payment_proof.max_px').' pixels wide and high.',
            'proof.uploaded' => 'The screenshot could not be uploaded. It may be larger than the server accepts.',
        ] + CheckoutItemsRules::messages();
    }

    /** The data PlaceOrder needs: the delivery address gets the customer's name and phone when it has none of its own. */
    public function orderData(): array
    {
        $data = $this->validated();
        unset($data['proof'], $data['reference']);   // the screenshot is stored after the order exists (OrderController)
        $customer = $data['customer'];
        foreach (['address', 'billing_address'] as $key) {
            if (isset($data[$key])) {
                $data[$key]['name'] = ($data[$key]['name'] ?? '') !== '' ? $data[$key]['name'] : $customer['name'];
                $data[$key]['phone'] = ($data[$key]['phone'] ?? '') !== '' ? $data[$key]['phone'] : $customer['phone'];
            }
        }
        return $data;
    }
}

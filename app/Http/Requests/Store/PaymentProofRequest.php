<?php

namespace App\Http\Requests\Store;

use App\Domain\Customer\Phone;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /store/orders/{number}/payment-proof?phone= (multipart): `proof` (jpg, png or webp, up to 4 MB, 100 to 6000 px) and an optional `reference`.
 * The order is looked up BEFORE the file is validated, so a wrong phone is the same 404 as an unknown order whatever the file is.
 */
class PaymentProofRequest extends FormRequest
{
    private ?Order $found = null;

    public function authorize(): bool
    {
        $order = Order::query()->where('order_number', strtoupper((string) $this->route('orderNumber')))->first();
        abort_unless(
            $order && Phone::normalise((string) $order->customer_phone) === Phone::normalise((string) $this->input('phone')),
            404,
            'Order not found. Check the order number and the phone number.',
        );
        $this->found = $order;

        return true;
    }

    public function order(): Order
    {
        return $this->found;
    }

    public function rules(): array
    {
        $px = config('shop.payment_proof');

        return [
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) $px['max_kb'],
                "dimensions:min_width={$px['min_px']},min_height={$px['min_px']},max_width={$px['max_px']},max_height={$px['max_px']}"],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        $px = config('shop.payment_proof');

        return [
            'proof.required' => 'Choose the screenshot of your payment.',
            'proof.image' => 'The proof must be a picture (JPG, PNG or WEBP).',
            'proof.mimes' => 'The proof must be a JPG, PNG or WEBP picture.',
            'proof.max' => 'The picture can be at most '.round($px['max_kb'] / 1024).' MB.',
            'proof.dimensions' => "The picture must be between {$px['min_px']} and {$px['max_px']} pixels wide and high.",
            'proof.uploaded' => 'The picture could not be uploaded. It may be larger than the server accepts.',
        ];
    }
}

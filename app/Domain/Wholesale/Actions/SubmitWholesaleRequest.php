<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Actions\UpsertCustomer;
use App\Domain\Customer\Phone;
use App\Domain\Notification\Notifier;
use App\Domain\Support\Settings;
use App\Domain\Wholesale\Models\WholesaleRequest;
use Illuminate\Support\Facades\DB;

/**
 * A guest asks for a wholesale price (POST /store/wholesale-requests). The customer record is found or created by normalised phone (the same
 * rule as an order), the request keeps a snapshot of the product name, and the owner is told (e-mail alert, push) after the request is saved.
 * A failing notification never fails the request.
 */
class SubmitWholesaleRequest
{
    public function __construct(private readonly UpsertCustomer $customers, private readonly Notifier $notifier) {}

    /** @param  array{name:string, phone:string, email?:?string, business_name?:?string, product_id?:?int, variant_id?:?int, product_name?:?string, quantity:int, message?:?string}  $data */
    public function handle(array $data): WholesaleRequest
    {
        $request = DB::transaction(function () use ($data) {
            $customer = $this->customers->handle(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null]);

            $row = WholesaleRequest::create([
                'reference' => 'WS-TMP-'.bin2hex(random_bytes(8)),   // replaced below, once the id exists
                'customer_id' => $customer->id,
                'name' => trim($data['name']),
                'phone' => Phone::normalise($data['phone']),
                'email' => trim((string) ($data['email'] ?? '')) !== '' ? strtolower(trim($data['email'])) : null,
                'business_name' => trim((string) ($data['business_name'] ?? '')) ?: null,
                'product_id' => $data['product_id'] ?? null,
                'variant_id' => $data['variant_id'] ?? null,
                'product_name' => $this->productName($data),
                'quantity' => (int) $data['quantity'],
                'message' => trim((string) ($data['message'] ?? '')) ?: null,
                'status' => 'new',
            ]);
            $row->forceFill(['reference' => sprintf('WS-%s-%06d', now()->format('Y'), $row->id)])->save();

            return $row;
        });

        $this->notifier->wholesaleRequested($request);

        return $request;
    }

    /** The name of the chosen product (and option) at this moment, or the free text the customer typed. */
    private function productName(array $data): string
    {
        $name = trim((string) ($data['product_name'] ?? ''));
        if (empty($data['product_id'])) {
            return $name;
        }
        $product = Product::withTrashed()->find($data['product_id']);
        $variant = ! empty($data['variant_id']) ? ProductVariant::query()->where('product_id', $data['product_id'])->find($data['variant_id']) : null;
        $label = $variant && $variant->name && $variant->name !== $product?->name ? $product?->name.' – '.$variant->name : ($product?->name ?? $name);

        return mb_substr($label ?: $name, 0, 255);
    }
}

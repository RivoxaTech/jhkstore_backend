<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Phone;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finds the customer of an order by normalised phone (or creates one) and keeps the contact details up to date: a changed name or a new
 * e-mail replaces the old one, an empty e-mail never wipes a stored one. Call inside the order transaction.
 */
class UpsertCustomer
{
    /** @param  array{name:string, phone:string, email?:?string, city?:?string}  $data */
    public function handle(array $data): Customer
    {
        $phone = Phone::normalise($data['phone']);
        $email = trim((string) ($data['email'] ?? '')) !== '' ? strtolower(trim($data['email'])) : null;
        $city = trim((string) ($data['city'] ?? '')) ?: null;
        $name = trim($data['name']);

        $customer = Customer::query()->where('phone', $phone)->lockForUpdate()->first();
        if (! $customer) {
            try {
                return Customer::create(['name' => $name, 'phone' => $phone, 'email' => $email, 'city' => $city]);
            } catch (UniqueConstraintViolationException) {
                // another order for the same phone was created at the same moment: use that row
                $customer = Customer::query()->where('phone', $phone)->lockForUpdate()->firstOrFail();
            }
        }

        $customer->fill(array_filter(['name' => $name, 'email' => $email, 'city' => $city], fn ($v) => $v !== null && $v !== ''))->save();

        return $customer;
    }

    /** Adds a placed order to the customer's totals (and takes a cancelled one off again). */
    public function recordOrder(Customer $customer, float|string $total, bool $placed = true): void
    {
        $sign = $placed ? 1 : -1;
        $customer->forceFill([
            'orders_count' => max(0, $customer->orders_count + $sign),
            'total_spent' => max(0, round((float) $customer->total_spent + $sign * (float) $total, 2)),
            'first_order_at' => $customer->first_order_at ?? ($placed ? now() : null),
            'last_order_at' => $placed ? now() : $customer->last_order_at,
        ])->save();
    }
}

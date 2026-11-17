<?php

namespace App\Domain\Promotion;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponUsage;
use App\Domain\Promotion\Models\Discount;
use Illuminate\Support\Collection;

/**
 * Reads the promotions for the price calculator and the product lists (the maths themselves are in CartPricing).
 * The live discounts are loaded once and kept for the current minute (two queries at most, however many products a list shows);
 * saving or deleting a discount clears them.
 */
class Promotions
{
    /** @var Collection<int, Discount>|null */
    private ?Collection $discounts = null;

    private ?string $loadedAt = null;

    /** @return Collection<int, Discount> the active, in-date discounts with their targets */
    public function liveDiscounts(): Collection
    {
        $minute = now()->format('Y-m-d H:i');
        if ($this->discounts === null || $this->loadedAt !== $minute) {
            $this->discounts = Discount::query()->live()->orderByDesc('priority')->orderBy('id')->get();
            if ($this->discounts->isNotEmpty()) {
                $this->discounts->load('targets');
            }
            $this->loadedAt = $minute;
        }

        return $this->discounts;
    }

    public function flush(): void
    {
        $this->discounts = null;
        $this->loadedAt = null;
    }

    /** The coupon with this code (any state), targets loaded. `$lock` = FOR UPDATE: only inside the order transaction, after the stock rows. */
    public function findCoupon(string $code, bool $lock = false): ?Coupon
    {
        $code = Coupon::normaliseCode($code);
        if ($code === '') {
            return null;
        }
        $coupon = Coupon::query()->where('code', $code)->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $coupon?->load('targets');
    }

    /** How many times this phone used the coupon (given-back uses do not count). */
    public function usesByPhone(Coupon $coupon, string $phone): int
    {
        return CouponUsage::query()->where('coupon_id', $coupon->id)->where('phone', $phone)->whereNull('released_at')->count();
    }

    /** Has the phone an earlier order that was not cancelled or rejected? (`first_order_only` coupons are for customers without one.) */
    public function hasEarlierOrder(string $phone): bool
    {
        $customerId = Customer::query()->where('phone', $phone)->value('id');

        return $customerId !== null
            && Order::query()->where('customer_id', $customerId)->whereNotIn('status', ['cancelled', 'rejected'])->exists();
    }
}

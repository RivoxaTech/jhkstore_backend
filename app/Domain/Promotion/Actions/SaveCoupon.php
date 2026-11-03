<?php

namespace App\Domain\Promotion\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponTarget;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Creates, updates and deletes coupons. A coupon that was used cannot be deleted (deactivate it instead). Audited. */
class SaveCoupon
{
    /** @param  array<string,mixed>  $data  validated by SaveCouponRequest (`target_ids` = ids of the kind the scope names) */
    public function handle(array $data, Admin $actor, ?Coupon $coupon = null): Coupon
    {
        return DB::transaction(function () use ($data, $actor, $coupon) {
            $creating = $coupon === null;
            $coupon ??= new Coupon;

            if (! empty($data['free_delivery'])) {
                $data['type'] = 'free_delivery';   // a free-delivery coupon has no value, no maximum
                $data['value'] = null;
                $data['max_discount'] = null;
            }
            $coupon->fill(collect($data)->except('target_ids')->all())->save();

            if (array_key_exists('target_ids', $data) || ($data['scope'] ?? null) === 'store') {
                $coupon->targets()->delete();
                $type = Coupon::TARGET_TYPE[$coupon->scope] ?? null;
                foreach ($type ? array_unique($data['target_ids'] ?? []) : [] as $id) {
                    CouponTarget::query()->create(['coupon_id' => $coupon->id, 'type' => $type, 'target_id' => (int) $id]);
                }
            }

            Audit::log($actor, $creating ? 'coupon.created' : 'coupon.updated', $coupon, [
                'code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value, 'scope' => $coupon->scope,
            ]);

            return $coupon->refresh()->load('targets');
        });
    }

    public function delete(Coupon $coupon, Admin $actor): void
    {
        if ($coupon->usages()->exists()) {
            throw new CommerceException('coupon_has_usages', 'This coupon was used on orders and cannot be deleted. Deactivate it instead.', 'coupon', 422);
        }
        Audit::log($actor, 'coupon.deleted', $coupon, ['code' => $coupon->code]);
        $coupon->delete();
    }
}

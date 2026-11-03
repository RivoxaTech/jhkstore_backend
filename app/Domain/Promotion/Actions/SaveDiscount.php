<?php

namespace App\Domain\Promotion\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Promotion\Models\Discount;
use App\Domain\Promotion\Models\DiscountTarget;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Creates or updates an automatic discount and its targets (the products, categories or sub-categories of its scope). Audited. */
class SaveDiscount
{
    /** @param  array<string,mixed>  $data  validated by SaveDiscountRequest (`target_ids` = ids of the kind the scope names) */
    public function handle(array $data, Admin $actor, ?Discount $discount = null): Discount
    {
        return DB::transaction(function () use ($data, $actor, $discount) {
            $creating = $discount === null;
            $discount ??= new Discount;
            $discount->fill(collect($data)->except('target_ids')->all())->save();

            if (array_key_exists('target_ids', $data) || ($data['scope'] ?? null) === 'store') {
                $discount->targets()->delete();
                $type = Discount::TARGET_TYPE[$discount->scope] ?? null;
                foreach ($type ? array_unique($data['target_ids'] ?? []) : [] as $id) {
                    DiscountTarget::query()->create(['discount_id' => $discount->id, 'type' => $type, 'target_id' => (int) $id]);
                }
            }
            app(\App\Domain\Promotion\Promotions::class)->flush();

            Audit::log($actor, $creating ? 'discount.created' : 'discount.updated', $discount, [
                'name' => $discount->name, 'type' => $discount->type, 'value' => $discount->value, 'scope' => $discount->scope,
            ]);

            return $discount->refresh()->load('targets');
        });
    }
}

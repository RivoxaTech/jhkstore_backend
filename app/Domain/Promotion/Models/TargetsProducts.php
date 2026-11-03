<?php

namespace App\Domain\Promotion\Models;

/**
 * What a discount or coupon applies to (`scope`): the whole store, listed products, every product of the listed categories (sub-categories
 * included, because a product always belongs to a category) or the products of the listed sub-categories only. The targets are rows of
 * `discount_targets` / `coupon_targets` (type product | category | sub_category); load the `targets` relation before matching.
 */
trait TargetsProducts
{
    public const SCOPES = ['store', 'products', 'categories', 'sub_categories'];

    public const TARGET_TYPE = ['products' => 'product', 'categories' => 'category', 'sub_categories' => 'sub_category'];

    public function appliesTo(int $productId, int $categoryId, ?int $subCategoryId): bool
    {
        if ($this->scope === 'store') {
            return true;
        }
        $type = self::TARGET_TYPE[$this->scope] ?? null;
        $id = match ($this->scope) {
            'products' => $productId,
            'categories' => $categoryId,
            'sub_categories' => $subCategoryId,
            default => null,
        };

        return $type !== null && $id !== null && $this->targets->contains(fn ($t) => $t->type === $type && (int) $t->target_id === $id);
    }

    /** live = active and inside its date window; an empty start / end is open-ended. */
    public function isLive(): bool
    {
        return $this->is_active && ! ($this->starts_at && $this->starts_at->isFuture()) && ! ($this->ends_at && $this->ends_at->isPast());
    }

    /** live | scheduled | expired | inactive */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at && $this->ends_at->isPast() => 'expired',
            default => 'live',
        };
    }
}

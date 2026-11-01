<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Actions\CartResolver;
use App\Domain\Cart\Actions\ManageCartItems;
use App\Domain\Cart\Models\Cart;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;

/**
 * "Order again": puts the options of an earlier order into the customer's cart.
 * The quantity is cut down to what is allowed today (max order qty, stock); an option that is not sold any more is skipped and reported.
 */
class ReorderOrder
{
    public function __construct(private readonly CartResolver $resolver, private readonly ManageCartItems $items) {}

    /** @return array{0: Cart, 1: list<array{variant_id:int, name:string, reason:string}>, 2: int} cart, skipped lines, lines added */
    public function handle(User $user, Order $order): array
    {
        $cart = $this->resolver->forUser($user) ?? $this->resolver->createForUser($user);
        $skipped = [];
        $added = 0;

        foreach ($order->items as $item) {
            try {
                $this->items->add($cart, $item->variant_id, $item->qty, clamp: true);
                $added++;
            } catch (CommerceException $e) {
                $skipped[] = ['variant_id' => $item->variant_id, 'name' => $item->option_name ?: $item->name, 'reason' => $e->getMessage()];
            }
        }

        return [$cart, $skipped, $added];
    }
}

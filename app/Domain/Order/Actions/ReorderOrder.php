<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Actions\CartResolver;
use App\Domain\Cart\Actions\ManageCartItems;
use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;

/**
 * "Order again": puts the options of an earlier order into the customer's cart of the same channel.
 * The quantity is cut down to what is allowed today (max order qty, stock); an option that is not sold any more is skipped and reported.
 */
class ReorderOrder
{
    public function __construct(private readonly CartResolver $resolver, private readonly ManageCartItems $items) {}

    /** @return array{0: Cart, 1: SalesChannel, 2: list<array{variant_id:int, name:string, reason:string}>, 3: int} cart, channel, skipped lines, lines added */
    public function handle(User $user, Order $order): array
    {
        $channel = SalesChannel::query()->where('is_active', true)->find($order->channel_id);
        if (! $channel) {
            throw new CommerceException('channel_unavailable', 'This sales channel is not available right now.');
        }

        $cart = $this->resolver->forUser($user, $channel) ?? $this->resolver->createForUser($user, $channel);
        $skipped = [];
        $added = 0;

        foreach ($order->items as $item) {
            try {
                $this->items->add($cart, $channel, $item->variant_id, $item->qty, clamp: true);
                $added++;
            } catch (CommerceException $e) {
                $skipped[] = ['variant_id' => $item->variant_id, 'name' => $item->option_name ?: $item->name, 'reason' => $e->getMessage()];
            }
        }

        return [$cart, $channel, $skipped, $added];
    }
}

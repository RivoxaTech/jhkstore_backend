<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\SalesChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds (or creates) the cart of the caller for one sales channel.
 *  - a logged-in customer: the active cart of (customer, channel)
 *  - a guest: the cart named by the `X-Cart-Token` header (Standard channel only; Instadent needs a login anyway)
 * A guest never chooses a token: the server creates it on the first "add" and returns it (header + body).
 */
class CartResolver
{
    public const HEADER = 'X-Cart-Token';

    /** The logged-in customer of the request, if any (an admin token is not a customer). */
    public function customer(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && $user->is_active ? $user : null;
    }

    public function find(Request $request, SalesChannel $channel): ?Cart
    {
        if ($user = $this->customer($request)) {
            return $this->forUser($user, $channel);
        }

        $token = (string) $request->header(self::HEADER, '');
        if (strlen($token) !== 40) {
            return null;
        }

        return Cart::query()->where('guest_token', $token)->where('channel_id', $channel->id)->where('status', Cart::ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    }

    public function findOrCreate(Request $request, SalesChannel $channel): Cart
    {
        if ($cart = $this->find($request, $channel)) {
            return $cart;
        }
        if ($user = $this->customer($request)) {
            return $this->createForUser($user, $channel);
        }

        return Cart::create([
            'guest_token' => Str::random(40), 'channel_id' => $channel->id, 'status' => Cart::ACTIVE,
            'expires_at' => now()->addDays(Cart::GUEST_DAYS),
        ]);
    }

    public function forUser(User $user, SalesChannel $channel): ?Cart
    {
        return Cart::query()->where('user_id', $user->id)->where('channel_id', $channel->id)->where('status', Cart::ACTIVE)->first();
    }

    /** One cart per customer and channel: the customer row is locked so two first requests cannot make two carts. */
    public function createForUser(User $user, SalesChannel $channel): Cart
    {
        return DB::transaction(function () use ($user, $channel) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            return $this->forUser($user, $channel) ?? Cart::create(['user_id' => $user->id, 'channel_id' => $channel->id, 'status' => Cart::ACTIVE]);
        });
    }

    /** A guest cart stays alive while it is used. */
    public function touch(Cart $cart): void
    {
        if ($cart->guest_token) {
            $cart->forceFill(['expires_at' => now()->addDays(Cart::GUEST_DAYS)])->save();
        } else {
            $cart->touch();
        }
    }
}

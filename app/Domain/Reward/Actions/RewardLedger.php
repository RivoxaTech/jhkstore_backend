<?php

namespace App\Domain\Reward\Actions;

use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Reward\Models\RewardTransaction;
use App\Domain\Reward\Models\RewardWallet;
use App\Domain\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The reward points service: the ONLY place that decides whether points exist (`Settings::rewardsEnabled()`), how many a purchase earns,
 * and how they are credited, spent and refunded. With the "Enable reward points" setting off nothing is earned, spent or shown.
 *
 * The ledger: `reward_transactions` is append-only; spendable coins live in "batches" (earn / reverse / adjust rows with
 * `remaining` > 0 that have not expired) and are spent earliest-expiry first. `reward_wallets` caches the balance.
 *
 *  earn    - when an order is DELIVERED (never on placing)       redeem  - when an order is PLACED (debit)
 *  reverse - the redeemed coins of a CANCELLED order, given back as a fresh batch (fresh expiry)       adjust - admin gift / demo coins
 * Every method that writes must run inside the caller's DB transaction.
 */
class RewardLedger
{
    public function __construct(private readonly Settings $settings) {}

    /** Is the reward points feature switched on? Every other part of the shop asks here. */
    public function enabled(): bool
    {
        return $this->settings->rewardsEnabled();
    }

    /**
     * Points a purchase line earns: the option's own points per unit (`reward_points`), or - when it has none - the store-wide earn rate
     * (points per 1 currency unit of the line total). Always 0 while the feature is off.
     */
    public function pointsForLine(int $optionPoints, int $qty, int $lineTotalCents): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        if ($optionPoints > 0) {
            return $optionPoints * $qty;
        }

        return (int) floor($this->settings->money($lineTotalCents) * (float) $this->settings->get('reward_earn_rate'));
    }

    /** Coins a customer can spend right now (sum of the batches that are available and not expired). 0 while the feature is off. */
    public function spendable(int $userId, bool $lock = false): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $query = $this->batches($userId);

        return (int) ($lock ? $query->lockForUpdate()->get()->sum('remaining') : $query->sum('remaining'));
    }

    /** Takes `$coins` from the earliest-expiring batches and records the spend. Throws when the wallet cannot cover it. */
    public function redeem(int $userId, int $coins, Order $order): RewardTransaction
    {
        $wallet = $this->wallet($userId);
        $batches = $this->batches($userId)->lockForUpdate()->get();

        if ($batches->sum('remaining') < $coins) {
            throw new CommerceException('insufficient_coins', 'You do not have enough coins for this order.', 'coins_to_redeem');
        }

        $redeem = RewardTransaction::create([
            'user_id' => $userId, 'type' => RewardTransaction::REDEEM, 'status' => RewardTransaction::CONSUMED, 'coins' => -$coins, 'remaining' => 0,
            'source_type' => 'order', 'source_id' => $order->id, 'credited_at' => now(), 'note' => "Redeemed on order {$order->order_number}",
        ]);

        $left = $coins;
        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $batch->remaining);
            $batch->remaining -= $take;
            if ($batch->remaining === 0) {
                $batch->status = RewardTransaction::CONSUMED;
            }
            $batch->save();
            DB::table('reward_redemptions')->insert(['redeem_transaction_id' => $redeem->id, 'earn_transaction_id' => $batch->id, 'coins' => $take]);
            $left -= $take;
        }

        $wallet->available -= $coins;
        $wallet->lifetime_redeemed += $coins;
        $wallet->save();

        return $redeem;
    }

    /**
     * Gives the redeemed coins of a cancelled order back (once) as a new batch, valid for the normal expiry period.
     * Runs even when the feature was switched off meanwhile: it returns what the customer paid with.
     */
    public function refund(Order $order): void
    {
        if ($order->coins_redeemed <= 0 || $this->existing($order, RewardTransaction::REVERSE)) {
            return;
        }

        $this->addBatch($order->user_id, RewardTransaction::REVERSE, $order->coins_redeemed, 'order', $order->id, "Coins returned: order {$order->order_number} was cancelled");
        $wallet = $this->wallet($order->user_id);
        $wallet->lifetime_redeemed = max(0, $wallet->lifetime_redeemed - $order->coins_redeemed);
        $wallet->save();
    }

    /** Credits the coins an order earns (once). Called when the order is delivered. */
    public function credit(Order $order): void
    {
        if (! $this->enabled() || $order->coins_to_earn <= 0 || $this->existing($order, RewardTransaction::EARN)) {
            return;
        }

        $this->addBatch($order->user_id, RewardTransaction::EARN, $order->coins_to_earn, 'order', $order->id, "Earned on order {$order->order_number}");
        $wallet = $this->wallet($order->user_id);
        $wallet->lifetime_earned += $order->coins_to_earn;
        $wallet->save();
    }

    /** Admin gift or correction (also used for the demo coins of the seeded customer). */
    public function grant(int $userId, int $coins, string $note, ?int $adminId = null): RewardTransaction
    {
        return DB::transaction(function () use ($userId, $coins, $note, $adminId) {
            $tx = $this->addBatch($userId, RewardTransaction::ADJUST, $coins, null, null, $note, $adminId);
            $this->wallet($userId)->increment('lifetime_earned', $coins);

            return $tx;
        });
    }

    /** Marks batches past their expiry as expired and takes the coins off the wallets. Returns how many batches expired. */
    public function expireDue(): int
    {
        $count = 0;
        RewardTransaction::query()->whereIn('type', $this->batchTypes())->where('status', RewardTransaction::AVAILABLE)
            ->where('remaining', '>', 0)->where('expires_at', '<=', now())->orderBy('id')->each(function (RewardTransaction $batch) use (&$count) {
                DB::transaction(function () use ($batch, &$count) {
                    $batch = RewardTransaction::query()->lockForUpdate()->find($batch->id);
                    if (! $batch || $batch->status !== RewardTransaction::AVAILABLE || $batch->remaining <= 0) {
                        return;
                    }
                    $wallet = $this->wallet($batch->user_id);
                    $wallet->available = max(0, $wallet->available - $batch->remaining);
                    $wallet->save();
                    $batch->update(['status' => RewardTransaction::EXPIRED]);
                    $count++;
                });
            });

        return $count;
    }

    /** The cached wallet row (created on first use, row-locked inside a transaction). */
    public function wallet(int $userId): RewardWallet
    {
        RewardWallet::query()->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return RewardWallet::query()->lockForUpdate()->findOrFail($userId);
    }

    private function addBatch(int $userId, string $type, int $coins, ?string $sourceType, ?int $sourceId, string $note, ?int $adminId = null): RewardTransaction
    {
        $tx = RewardTransaction::create([
            'user_id' => $userId, 'type' => $type, 'status' => RewardTransaction::AVAILABLE, 'coins' => $coins, 'remaining' => $coins,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'credited_at' => now(),
            'expires_at' => now()->addDays($this->settings->rewardExpiryDays()), 'note' => $note, 'created_by' => $adminId,
        ]);
        $wallet = $this->wallet($userId);
        $wallet->available += $coins;
        $wallet->save();

        return $tx;
    }

    private function existing(Order $order, string $type): bool
    {
        return RewardTransaction::query()->where('source_type', 'order')->where('source_id', $order->id)->where('type', $type)->exists();
    }

    private function batches(int $userId)
    {
        return RewardTransaction::query()
            ->where('user_id', $userId)->whereIn('type', $this->batchTypes())
            ->where('status', RewardTransaction::AVAILABLE)->where('remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id');
    }

    /** @return list<string> */
    private function batchTypes(): array
    {
        return [RewardTransaction::EARN, RewardTransaction::REVERSE, RewardTransaction::ADJUST];
    }
}

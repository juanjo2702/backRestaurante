<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointTransaction;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoyaltyPointService
{
    public function awardFromOrder(Pedido $pedido, ?User $actor = null): ?LoyaltyPoint
    {
        if ($pedido->estado !== 'pagado' || empty($pedido->telefono_cliente)) {
            return null;
        }

        $pointsEarned = (int) floor((float) $pedido->total / 10);
        if ($pointsEarned <= 0) {
            return null;
        }

        return DB::transaction(function () use ($pedido, $actor, $pointsEarned) {
            $reference = 'ORDER-' . $pedido->id . '-EARN';

            $existingTransaction = LoyaltyPointTransaction::where('reference', $reference)->first();
            if ($existingTransaction) {
                return $existingTransaction->loyaltyPoint;
            }

            $account = $this->findOrCreateAccount(
                customerName: $pedido->nombre_cliente,
                customerPhone: $pedido->telefono_cliente,
                user: $actor ?? $pedido->usuario,
            );

            $balanceAfter = $account->points + $pointsEarned;

            LoyaltyPointTransaction::create([
                'loyalty_point_id' => $account->id,
                'pedido_id' => $pedido->id,
                'user_id' => $actor?->id ?? $pedido->usuario_id,
                'type' => 'earn',
                'points_delta' => $pointsEarned,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'notes' => "Acumulacion por pedido #{$pedido->id}",
            ]);

            $account->update([
                'customer_name' => $pedido->nombre_cliente ?: $account->customer_name,
                'user_id' => $actor?->id ?? $pedido->usuario_id,
                'points' => $balanceAfter,
                'last_order_id' => $pedido->id,
                'last_activity_at' => now(),
            ]);

            return $account->fresh('transactions');
        });
    }

    public function redeemByPhone(string $phone, int $points, ?string $reference = null, ?string $notes = null, ?User $actor = null): LoyaltyPoint
    {
        return DB::transaction(function () use ($phone, $points, $reference, $notes, $actor) {
            $account = LoyaltyPoint::where('customer_phone', $phone)->first();

            if (!$account) {
                throw new \RuntimeException('No se encontraron puntos para canjear');
            }

            if ($account->points < $points) {
                throw new \RuntimeException('Puntos insuficientes');
            }

            $transactionReference = $reference ?: 'REDEEM-' . now()->format('YmdHis') . '-' . $account->id;
            if (LoyaltyPointTransaction::where('reference', $transactionReference)->exists()) {
                return $account->fresh('transactions');
            }

            $balanceAfter = $account->points - $points;

            LoyaltyPointTransaction::create([
                'loyalty_point_id' => $account->id,
                'pedido_id' => null,
                'user_id' => $actor?->id,
                'type' => 'redeem',
                'points_delta' => -1 * $points,
                'balance_after' => $balanceAfter,
                'reference' => $transactionReference,
                'notes' => $notes ?: 'Canje de puntos',
            ]);

            $account->update([
                'points' => $balanceAfter,
                'last_activity_at' => now(),
            ]);

            return $account->fresh('transactions');
        });
    }

    public function adjustBalance(LoyaltyPoint $account, int $pointsDelta, ?string $reference = null, ?string $notes = null, ?User $actor = null, ?Pedido $pedido = null): LoyaltyPoint
    {
        return DB::transaction(function () use ($account, $pointsDelta, $reference, $notes, $actor, $pedido) {
            $balanceAfter = max(0, $account->points + $pointsDelta);
            $appliedDelta = $balanceAfter - $account->points;
            $transactionReference = $reference ?: 'ADJUST-' . now()->format('YmdHis') . '-' . $account->id;

            if (LoyaltyPointTransaction::where('reference', $transactionReference)->exists()) {
                return $account->fresh('transactions');
            }

            LoyaltyPointTransaction::create([
                'loyalty_point_id' => $account->id,
                'pedido_id' => $pedido?->id,
                'user_id' => $actor?->id,
                'type' => 'adjustment',
                'points_delta' => $appliedDelta,
                'balance_after' => $balanceAfter,
                'reference' => $transactionReference,
                'notes' => $notes ?: 'Ajuste manual de puntos',
            ]);

            $account->update([
                'points' => $balanceAfter,
                'last_order_id' => $pedido?->id ?? $account->last_order_id,
                'last_activity_at' => now(),
            ]);

            return $account->fresh('transactions');
        });
    }

    public function findOrCreateAccount(?string $customerName, string $customerPhone, ?User $user = null): LoyaltyPoint
    {
        return LoyaltyPoint::firstOrCreate(
            ['customer_phone' => $customerPhone],
            [
                'customer_name' => $customerName,
                'user_id' => $user?->id,
                'points' => 0,
                'last_activity_at' => now(),
            ]
        );
    }
}

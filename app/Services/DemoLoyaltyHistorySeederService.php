<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointTransaction;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Collection;

class DemoLoyaltyHistorySeederService
{
    public function __construct(
        private readonly LoyaltyPointService $loyaltyPointService,
    ) {
    }

    public function resetDemoData(): void
    {
        $transactionIds = LoyaltyPointTransaction::where('reference', 'like', 'DEMO-%')
            ->pluck('loyalty_point_id')
            ->unique();

        LoyaltyPointTransaction::where('reference', 'like', 'DEMO-%')->delete();

        LoyaltyPoint::whereIn('id', $transactionIds)->get()->each(function (LoyaltyPoint $account) {
            $latest = $account->transactions()->latest('id')->first();

            $account->update([
                'points' => $latest?->balance_after ?? 0,
                'last_activity_at' => $latest?->created_at,
                'last_order_id' => $latest?->pedido_id,
            ]);
        });
    }

    public function awardForOrders(Collection $orders, ?User $actor = null): void
    {
        $orders
            ->filter(fn (Pedido $pedido) => $pedido->estado === 'pagado' && filled($pedido->telefono_cliente))
            ->sortBy('created_at')
            ->each(function (Pedido $pedido) use ($actor) {
                $points = (int) floor((float) $pedido->total / 10);
                if ($points <= 0) {
                    return;
                }

                $account = $this->loyaltyPointService->findOrCreateAccount(
                    customerName: $pedido->nombre_cliente,
                    customerPhone: $pedido->telefono_cliente,
                    user: $actor ?? $pedido->usuario,
                );

                $this->loyaltyPointService->adjustBalance(
                    account: $account,
                    pointsDelta: $points,
                    reference: 'DEMO-EARN-' . $pedido->id,
                    notes: "Acumulacion demo por pedido #{$pedido->id}",
                    actor: $actor ?? $pedido->usuario,
                    pedido: $pedido
                );
            });
    }

    public function seedRedeems(?User $actor = null): void
    {
        LoyaltyPoint::where('points', '>=', 8)
            ->orderByDesc('points')
            ->take(4)
            ->get()
            ->each(function (LoyaltyPoint $account, int $index) use ($actor) {
                $points = min(5 + $index, max(1, $account->points - 1));

                $this->loyaltyPointService->redeemByPhone(
                    phone: $account->customer_phone,
                    points: $points,
                    reference: 'DEMO-REDEEM-' . $account->id,
                    notes: 'Canje demo para QA',
                    actor: $actor
                );
            });
    }
}

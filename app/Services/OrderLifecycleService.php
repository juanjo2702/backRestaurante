<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\OrderStatusHistory;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderLifecycleService
{
    private const ALLOWED_TRANSITIONS = [
        'pendiente' => ['preparando', 'cancelado'],
        'preparando' => ['listo', 'cancelado'],
        'listo' => ['servido', 'pagado', 'cancelado'],
        'servido' => ['pagado', 'cancelado'],
        'pagado' => [],
        'cancelado' => [],
    ];

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly RealtimeEventService $realtime,
        private readonly LoyaltyPointService $loyaltyPointService,
    ) {
    }

    public function transition(Pedido $pedido, string $toStatus, ?User $actor = null, ?string $reason = null): Pedido
    {
        $fromStatus = $pedido->estado;

        if ($fromStatus === $toStatus) {
            return $pedido->fresh(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']);
        }

        if (!in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus] ?? [], true)) {
            throw new \RuntimeException("Transición inválida de {$fromStatus} a {$toStatus}");
        }

        return DB::transaction(function () use ($pedido, $fromStatus, $toStatus, $actor, $reason) {
            if ($toStatus === 'listo') {
                $this->inventoryService->validateAvailabilityForOrder($pedido->loadMissing('detalles.producto.ingredientes'));
                $this->inventoryService->consumeForOrder($pedido, $actor);
            }

            if ($toStatus === 'cancelado' && in_array($fromStatus, ['listo', 'servido'], true)) {
                $this->inventoryService->reverseConsumptionForOrder($pedido->loadMissing('detalles.producto.ingredientes'), $actor);
            }

            $update = ['estado' => $toStatus];
            if ($toStatus === 'pagado') {
                $update['fecha_pago'] = now();
            }

            $pedido->update($update);

            OrderStatusHistory::create([
                'pedido_id' => $pedido->id,
                'user_id' => $actor?->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
            ]);

            if ($pedido->estado === 'pagado') {
                $this->loyaltyPointService->awardFromOrder($pedido, $actor);
            }

            if ($toStatus === 'pagado' && $pedido->mesa_id) {
                Mesa::where('id', $pedido->mesa_id)->update(['estado' => 'ocupada']);
            }

            $channels = ['global', 'role_admin', 'role_kitchen', 'role_cashier'];
            if ($pedido->usuario_id) {
                $channels[] = 'user_' . $pedido->usuario_id;
            }

            $this->realtime->publish(
                type: 'order.status.updated',
                payload: [
                    'order_id' => $pedido->id,
                    'status' => $pedido->estado,
                    'table_number' => $pedido->mesa?->numero,
                ],
                channels: $channels,
                aggregateId: 'order:' . $pedido->id
            );

            return $pedido->fresh(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']);
        });
    }
}

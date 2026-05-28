<?php

namespace App\Services;

use App\Models\Ingrediente;
use App\Models\InventoryMovement;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DemoInventoryHistorySeederService
{
    public function resetDemoData(): void
    {
        InventoryMovement::where('reference', 'like', 'DEMO-%')->delete();
    }

    public function restock(Ingrediente $ingrediente, float $quantity, \Carbon\Carbon $occurredAt, ?User $actor = null, ?string $reference = null, ?string $notes = null): InventoryMovement
    {
        return $this->createMovement(
            ingrediente: $ingrediente->fresh(),
            type: 'restock',
            quantity: $quantity,
            occurredAt: $occurredAt,
            reference: $reference ?: 'DEMO-RESTOCK-' . $ingrediente->id . '-' . $occurredAt->format('YmdHis'),
            notes: $notes ?: 'Reposicion demo de inventario',
            actor: $actor,
            pedido: null
        );
    }

    public function adjustment(Ingrediente $ingrediente, float $quantityDelta, \Carbon\Carbon $occurredAt, ?User $actor = null, ?string $reference = null, ?string $notes = null): InventoryMovement
    {
        return $this->createMovement(
            ingrediente: $ingrediente->fresh(),
            type: 'adjustment',
            quantity: $quantityDelta,
            occurredAt: $occurredAt,
            reference: $reference ?: 'DEMO-ADJUST-' . $ingrediente->id . '-' . $occurredAt->format('YmdHis'),
            notes: $notes ?: 'Ajuste demo por merma o conteo',
            actor: $actor,
            pedido: null
        );
    }

    public function consumeForOrder(Pedido $pedido, \Carbon\Carbon $occurredAt, ?User $actor = null): void
    {
        $pedido->loadMissing('detalles.producto.ingredientes');

        foreach ($pedido->detalles as $detalle) {
            foreach ($detalle->producto->ingredientes as $ingrediente) {
                $required = (float) $ingrediente->pivot->cantidad_necesaria * (float) $detalle->cantidad;

                $this->createMovement(
                    ingrediente: $ingrediente->fresh(),
                    type: 'consumption',
                    quantity: -1 * $required,
                    occurredAt: $occurredAt,
                    reference: 'DEMO-CONSUME-' . $pedido->id . '-' . $ingrediente->id . '-' . $occurredAt->format('YmdHis'),
                    notes: "Consumo demo por pedido #{$pedido->id}",
                    actor: $actor,
                    pedido: $pedido
                );
            }
        }
    }

    public function reverseForOrder(Pedido $pedido, \Carbon\Carbon $occurredAt, ?User $actor = null): void
    {
        $pedido->loadMissing('detalles.producto.ingredientes');

        foreach ($pedido->detalles as $detalle) {
            foreach ($detalle->producto->ingredientes as $ingrediente) {
                $required = (float) $ingrediente->pivot->cantidad_necesaria * (float) $detalle->cantidad;

                $this->createMovement(
                    ingrediente: $ingrediente->fresh(),
                    type: 'reversal',
                    quantity: $required,
                    occurredAt: $occurredAt,
                    reference: 'DEMO-REVERSAL-' . $pedido->id . '-' . $ingrediente->id . '-' . $occurredAt->format('YmdHis'),
                    notes: "Reversion demo por pedido cancelado #{$pedido->id}",
                    actor: $actor,
                    pedido: $pedido
                );
            }
        }
    }

    private function createMovement(
        Ingrediente $ingrediente,
        string $type,
        float $quantity,
        \Carbon\Carbon $occurredAt,
        string $reference,
        string $notes,
        ?User $actor = null,
        ?Pedido $pedido = null
    ): InventoryMovement {
        return DB::transaction(function () use ($ingrediente, $type, $quantity, $occurredAt, $reference, $notes, $actor, $pedido) {
            $ingrediente->refresh();
            $before = (float) $ingrediente->stock_actual;
            $after = $before + $quantity;

            if ($after < 0) {
                $after = 0;
            }

            $ingrediente->update(['stock_actual' => $after]);

            return InventoryMovement::create([
                'ingrediente_id' => $ingrediente->id,
                'pedido_id' => $pedido?->id,
                'user_id' => $actor?->id,
                'type' => $type,
                'quantity' => abs($quantity),
                'stock_before' => $before,
                'stock_after' => $after,
                'reference' => $reference,
                'notes' => $notes,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        });
    }
}

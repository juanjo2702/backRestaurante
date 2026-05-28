<?php

namespace App\Services;

use App\Models\Ingrediente;
use App\Models\InventoryMovement;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function validateAvailabilityForOrder(Pedido $pedido): void
    {
        foreach ($pedido->detalles as $detalle) {
            $producto = $detalle->producto()->with('ingredientes')->first();

            foreach ($producto->ingredientes as $ingrediente) {
                $required = $ingrediente->pivot->cantidad_necesaria * $detalle->cantidad;

                if ((float) $ingrediente->stock_actual < $required) {
                    throw new \RuntimeException("Stock insuficiente para {$producto->nombre} ({$ingrediente->nombre})");
                }
            }
        }
    }

    public function consumeForOrder(Pedido $pedido, ?User $actor = null): void
    {
        foreach ($pedido->detalles as $detalle) {
            $producto = $detalle->producto()->with('ingredientes')->first();

            foreach ($producto->ingredientes as $ingrediente) {
                $required = $ingrediente->pivot->cantidad_necesaria * $detalle->cantidad;
                $this->move(
                    ingrediente: $ingrediente,
                    quantity: $required,
                    type: 'consumption',
                    actor: $actor,
                    pedido: $pedido,
                    notes: "Consumo por pedido #{$pedido->id}"
                );
            }
        }
    }

    public function reverseConsumptionForOrder(Pedido $pedido, ?User $actor = null): void
    {
        foreach ($pedido->detalles as $detalle) {
            $producto = $detalle->producto()->with('ingredientes')->first();

            foreach ($producto->ingredientes as $ingrediente) {
                $required = $ingrediente->pivot->cantidad_necesaria * $detalle->cantidad;

                $this->move(
                    ingrediente: $ingrediente,
                    quantity: -1 * $required,
                    type: 'reversal',
                    actor: $actor,
                    pedido: $pedido,
                    notes: "Reversión por cancelación del pedido #{$pedido->id}"
                );
            }
        }
    }

    public function adjustment(Ingrediente $ingrediente, float $quantity, string $reference, ?string $notes = null, ?User $actor = null): InventoryMovement
    {
        return $this->move(
            ingrediente: $ingrediente,
            quantity: -1 * $quantity,
            type: 'adjustment',
            actor: $actor,
            pedido: null,
            reference: $reference,
            notes: $notes
        );
    }

    private function move(
        Ingrediente $ingrediente,
        float $quantity,
        string $type,
        ?User $actor = null,
        ?Pedido $pedido = null,
        ?string $reference = null,
        ?string $notes = null
    ): InventoryMovement {
        return DB::transaction(function () use ($ingrediente, $quantity, $type, $actor, $pedido, $reference, $notes) {
            $ingrediente->refresh();
            $before = (float) $ingrediente->stock_actual;
            $after = $before - $quantity;

            if ($after < 0) {
                throw new \RuntimeException("Stock negativo no permitido para {$ingrediente->nombre}");
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
            ]);
        });
    }
}

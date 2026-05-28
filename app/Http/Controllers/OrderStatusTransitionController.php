<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\OrderLifecycleService;
use Illuminate\Http\Request;

class OrderStatusTransitionController extends Controller
{
    public function store(Request $request, Pedido $order, OrderLifecycleService $orderLifecycleService)
    {
        $validated = $request->validate([
            'status' => 'required|in:preparando,listo,servido,pagado,cancelado',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $order = $orderLifecycleService->transition(
                pedido: $order->loadMissing('detalles.producto.ingredientes', 'mesa'),
                toStatus: $validated['status'],
                actor: $request->user(),
                reason: $validated['reason'] ?? null
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($order);
    }
}

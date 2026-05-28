<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyPoint;
use App\Models\Pedido;
use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyPointService $loyaltyPointService,
    ) {
    }

    public function addPointsFromOrder(Pedido $order)
    {
        return $this->loyaltyPointService->awardFromOrder($order, $order->usuario);
    }

    public function getPoints(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $loyalty = LoyaltyPoint::with('transactions')
            ->where('customer_phone', $request->phone)
            ->first();

        if (!$loyalty) {
            return response()->json([
                'points' => 0,
                'message' => 'No se encontraron puntos para este telefono',
            ]);
        }

        return response()->json([
            'points' => $loyalty->points,
            'customer_name' => $loyalty->customer_name,
            'last_activity' => $loyalty->last_activity_at,
            'transactions' => $loyalty->transactions()
                ->latest()
                ->take(10)
                ->get()
                ->map(fn ($transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'points_delta' => $transaction->points_delta,
                    'balance_after' => $transaction->balance_after,
                    'reference' => $transaction->reference,
                    'created_at' => $transaction->created_at,
                ]),
        ]);
    }

    public function redeemPoints(Request $request, LoyaltyPointService $loyaltyPointService)
    {
        $request->validate([
            'phone' => 'required|string',
            'points' => 'required|integer|min:1',
        ]);

        try {
            $loyalty = $loyaltyPointService->redeemByPhone(
                phone: $request->phone,
                points: (int) $request->points,
                actor: $request->user()
            );
        } catch (\RuntimeException $exception) {
            $status = str_contains(mb_strtolower($exception->getMessage()), 'insuficientes') ? 400 : 404;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'message' => 'Puntos canjeados exitosamente',
            'remaining_points' => $loyalty->points,
        ]);
    }
}

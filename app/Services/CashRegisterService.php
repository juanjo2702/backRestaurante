<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use App\Models\Pedido;
use App\Models\PaymentTransaction;
use App\Models\User;

class CashRegisterService
{
    public function open(User $user, float $openingAmount, ?string $notes = null): CashRegisterSession
    {
        $openSession = CashRegisterSession::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($openSession) {
            return $openSession;
        }

        return CashRegisterSession::create([
            'user_id' => $user->id,
            'status' => 'open',
            'opening_amount' => $openingAmount,
            'opened_at' => now(),
            'notes' => $notes,
        ]);
    }

    public function close(CashRegisterSession $session, float $closingAmount, ?string $notes = null): CashRegisterSession
    {
        $pendingOrders = Pedido::where('created_at', '>=', $session->opened_at)
            ->whereNotIn('estado', ['pagado', 'cancelado'])
            ->count();

        if ($pendingOrders > 0) {
            throw new \RuntimeException('No se puede cerrar caja con pedidos pendientes');
        }

        $expected = (float) PaymentTransaction::where('status', 'confirmed')
            ->where('confirmed_by', $session->user_id)
            ->whereBetween('confirmed_at', [$session->opened_at, now()])
            ->sum('amount');

        $session->update([
            'status' => 'closed',
            'closing_amount' => $closingAmount,
            'expected_amount' => $expected,
            'closed_at' => now(),
            'notes' => $notes,
        ]);

        return $session->fresh();
    }
}


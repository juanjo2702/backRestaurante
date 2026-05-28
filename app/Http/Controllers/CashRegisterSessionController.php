<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Services\CashRegisterService;
use Illuminate\Http\Request;

class CashRegisterSessionController extends Controller
{
    public function open(Request $request, CashRegisterService $cashRegisterService)
    {
        $validated = $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $session = $cashRegisterService->open(
            $request->user(),
            (float) $validated['opening_amount'],
            $validated['notes'] ?? null
        );

        return response()->json($session, 201);
    }

    public function close(Request $request, CashRegisterSession $session, CashRegisterService $cashRegisterService)
    {
        $validated = $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $session = $cashRegisterService->close(
            $session,
            (float) $validated['closing_amount'],
            $validated['notes'] ?? null
        );

        return response()->json($session);
    }
}

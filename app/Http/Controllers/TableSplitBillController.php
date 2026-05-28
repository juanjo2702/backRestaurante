<?php

namespace App\Http\Controllers;

use App\Models\BillAccount;
use App\Models\Mesa;
use App\Services\SplitBillService;
use Illuminate\Http\Request;

class TableSplitBillController extends Controller
{
    public function show(Mesa $mesa, SplitBillService $splitBillService)
    {
        $payload = $splitBillService->getPayloadForMesa($mesa);

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function initialize(Request $request, Mesa $mesa, SplitBillService $splitBillService)
    {
        $validated = $request->validate([
            'strategy' => 'sometimes|in:by_session,equal_split',
            'reset' => 'sometimes|boolean',
        ]);

        try {
            $payload = $splitBillService->initializeForMesa(
                $mesa,
                $validated['strategy'] ?? 'by_session',
                $validated['reset'] ?? false,
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $payload]);
    }

    public function createAccount(Request $request, Mesa $mesa, SplitBillService $splitBillService)
    {
        $validated = $request->validate([
            'display_name' => 'required|string|max:255',
        ]);

        try {
            $payload = $splitBillService->createManualAccount($mesa, $validated['display_name']);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $payload], 201);
    }

    public function updateAccount(Request $request, Mesa $mesa, BillAccount $account, SplitBillService $splitBillService)
    {
        $validated = $request->validate([
            'action' => 'sometimes|in:rename,merge,reorder',
            'display_name' => 'sometimes|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
            'target_account_id' => 'sometimes|integer|exists:bill_accounts,id',
        ]);

        try {
            $payload = $splitBillService->updateAccount($mesa, $account, $validated);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $payload]);
    }

    public function mutateAllocations(Request $request, Mesa $mesa, SplitBillService $splitBillService)
    {
        $validated = $request->validate([
            'action' => 'required|in:move,split',
            'allocation_id' => 'required|integer|exists:bill_account_allocations,id',
            'target_account_id' => 'required|integer|exists:bill_accounts,id',
            'amount' => 'nullable|numeric|min:0.01',
            'ratio' => 'nullable|numeric|min:0.01|max:0.99',
        ]);

        if ($validated['action'] === 'split' && !isset($validated['amount']) && !isset($validated['ratio'])) {
            return response()->json([
                'message' => 'Debes indicar un monto o ratio para dividir el item',
            ], 422);
        }

        try {
            $payload = $splitBillService->mutateAllocations($mesa, $validated);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $payload]);
    }
}

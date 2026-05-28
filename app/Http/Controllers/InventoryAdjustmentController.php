<?php

namespace App\Http\Controllers;

use App\Models\Ingrediente;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function store(Request $request, InventoryService $inventoryService)
    {
        $validated = $request->validate([
            'ingredient_id' => 'required|exists:ingredientes,id',
            'type' => 'required|string|in:add,subtract',
            'quantity' => 'required|numeric|min:0.001',
            'reference' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $quantity = (float) $validated['quantity'];
        if ($validated['type'] === 'add') {
            $quantity *= -1;
        }

        $movement = $inventoryService->adjustment(
            ingrediente: Ingrediente::findOrFail($validated['ingredient_id']),
            quantity: $quantity,
            reference: $validated['reference'],
            notes: $validated['notes'] ?? null,
            actor: $request->user()
        );

        return response()->json($movement, 201);
    }
}

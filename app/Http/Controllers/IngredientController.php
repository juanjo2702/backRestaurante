<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ingrediente;
use Carbon\Carbon;

class IngredientController extends Controller
{
    public function index()
    {
        return response()->json(Ingrediente::with('categoria')->get());
    }

    public function show(Ingrediente $ingredient)
    {
        return response()->json($ingredient->load('categoria'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'categoria_id' => 'required|exists:categorias,id',
            'unidad_medida' => 'required|string',
            'stock_actual' => 'required|numeric|min:0',
            'stock_minimo' => 'required|numeric|min:0',
            'costo_unitario' => 'nullable|numeric|min:0',
            'fecha_vencimiento' => 'nullable|date',
            'icono' => 'nullable|string|max:50',
        ]);

        $ingrediente = Ingrediente::create($validated);

        return response()->json($ingrediente->load('categoria'), 201);
    }

    public function update(Request $request, Ingrediente $ingredient)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'categoria_id' => 'sometimes|required|exists:categorias,id',
            'unidad_medida' => 'sometimes|required|string',
            'stock_actual' => 'sometimes|required|numeric|min:0',
            'stock_minimo' => 'sometimes|required|numeric|min:0',
            'costo_unitario' => 'nullable|numeric|min:0',
            'fecha_vencimiento' => 'nullable|date',
            'icono' => 'nullable|string|max:50',
        ]);

        $ingredient->update($validated);

        return response()->json($ingredient->load('categoria'));
    }

    public function destroy(Ingrediente $ingredient)
    {
        $ingredient->delete();
        return response()->json(['message' => 'Ingrediente eliminado']);
    }

    public function lowStock()
    {
        $ingredients = Ingrediente::with('categoria')
            ->whereRaw('stock_actual <= stock_minimo')
            ->orderBy('stock_actual', 'asc')
            ->get();

        return response()->json($ingredients);
    }

    public function expiring()
    {
        $thresholdDate = Carbon::now()->addDays(7); // Ingredientes que vencen en los próximos 7 días
        $ingredients = Ingrediente::with('categoria')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<=', $thresholdDate)
            ->orderBy('fecha_vencimiento', 'asc')
            ->get();

        return response()->json($ingredients);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        return Categoria::all();
    }

    public function show(Categoria $category)
    {
        return response()->json($category);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:categorias',
            'tipo' => 'required|in:menu,inventario',
            'activo' => 'boolean',
            'icono' => 'nullable|string'
        ]);

        $categoria = Categoria::create($validated);

        return response()->json($categoria, 201);
    }

    public function update(Request $request, Categoria $category)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255|unique:categorias,nombre,' . $category->id,
            'tipo' => 'sometimes|required|in:menu,inventario',
            'activo' => 'boolean',
            'icono' => 'nullable|string'
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    public function destroy(Categoria $category)
    {
        // En lugar de eliminar, podríamos desactivar si hay productos asociados.
        // Por ahora, permitir eliminación si no viola integridad referencial (BD se encarga).
        // Pero mejor usar SoftDeletes o desactivación. El usuario pidió "habilitar/inhabilitar".
        // La inhabilitación se hace via update(activo: false).
        // El delete físico puede fallar si hay productos.

        try {
            $category->delete();
            return response()->json(['message' => 'Categoría eliminada']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se puede eliminar la categoría porque tiene elementos asociados'], 400);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Producto;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Producto::with(['categoria', 'ingredientes']);

        if ($request->has('categoria')) {
            $query->whereHas('categoria', function ($q) use ($request) {
                $q->where('nombre', $request->categoria);
            });
        }

        return response()->json($query->get());
    }

    public function show(Producto $product)
    {
        return response()->json($product->load(['categoria', 'ingredientes']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'categoria_id' => 'required|exists:categorias,id',
            'precio' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'disponible' => 'boolean',
            'ingredientes' => 'nullable|array',
            'ingredientes.*.id' => 'required_with:ingredientes|exists:ingredientes,id',
            'ingredientes.*.cantidad' => 'required_with:ingredientes|numeric|min:0.001'
        ]);

        if ($request->hasFile('imagen')) {
            $validated['imagen_url'] = $request->file('imagen')->store('products', 'public');
        }

        $product = Producto::create($validated);

        if (isset($validated['ingredientes'])) {
            $syncData = [];
            foreach ($validated['ingredientes'] as $ing) {
                $syncData[$ing['id']] = ['cantidad_necesaria' => $ing['cantidad']];
            }
            $product->ingredientes()->sync($syncData);
        }

        return response()->json($product->load(['categoria', 'ingredientes']), 201);
    }

    public function update(Request $request, Producto $product)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'categoria_id' => 'sometimes|required|exists:categorias,id',
            'precio' => 'sometimes|required|numeric|min:0',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'disponible' => 'boolean',
            'ingredientes' => 'nullable|array',
            'ingredientes.*.id' => 'required_with:ingredientes|exists:ingredientes,id',
            'ingredientes.*.cantidad' => 'required_with:ingredientes|numeric|min:0.001'
        ]);

        if ($request->hasFile('imagen')) {
            $rawPath = $product->getAttributes()['imagen_url'] ?? null;
            if ($rawPath && !str_starts_with($rawPath, 'http')) {
                Storage::disk('public')->delete($rawPath);
            }
            $validated['imagen_url'] = $request->file('imagen')->store('products', 'public');
        }

        $product->update($validated);

        if (isset($validated['ingredientes'])) {
            $syncData = [];
            foreach ($validated['ingredientes'] as $ing) {
                $syncData[$ing['id']] = ['cantidad_necesaria' => $ing['cantidad']];
            }
            $product->ingredientes()->sync($syncData);
        }

        return response()->json($product->load(['categoria', 'ingredientes']));
    }

    public function destroy(Producto $product)
    {
        $rawPath = $product->getAttributes()['imagen_url'] ?? null;
        if ($rawPath && !str_starts_with($rawPath, 'http')) {
            Storage::disk('public')->delete($rawPath);
        }

        $product->delete();

        return response()->json(['message' => 'Producto eliminado']);
    }

    public function publicIndex(Request $request)
    {
        $query = Producto::with(['categoria' => function ($q) {
            $q->select('id', 'nombre', 'icono');
        }])
        ->where('disponible', true)
        ->select('id', 'nombre', 'categoria_id', 'precio', 'descripcion', 'imagen_url');

        if ($request->has('categoria')) {
            $query->whereHas('categoria', function ($q) use ($request) {
                $q->where('nombre', $request->categoria);
            });
        }

        $productos = $query->get()->map(function ($producto) {
            return [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'precio' => $producto->precio,
                'descripcion' => $producto->descripcion,
                'imagen_url' => $producto->imagen_url,
                'categoria' => $producto->categoria ? [
                    'id' => $producto->categoria->id,
                    'nombre' => $producto->categoria->nombre,
                    'icono' => $producto->categoria->icono,
                ] : null,
            ];
        });

        return response()->json($productos);
    }
}

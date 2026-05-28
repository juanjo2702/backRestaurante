<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\Producto;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        // Categorías de Ingredientes
        $cats = ['perecedero', 'no_perecedero'];
        foreach ($cats as $catName) {
            Categoria::firstOrCreate(['nombre' => $catName, 'tipo' => 'inventario']);
        }

        $perecedero = Categoria::where('nombre', 'perecedero')->first();
        $noPerecedero = Categoria::where('nombre', 'no_perecedero')->first();

        // Ingredientes
        $ingredientes = [
            ['name' => 'Carne de Res', 'cat' => $perecedero->id, 'unit' => 'kg', 'stock' => 15, 'min' => 5, 'cost' => 45],
            ['name' => 'Pollo', 'cat' => $perecedero->id, 'unit' => 'kg', 'stock' => 12, 'min' => 4, 'cost' => 25],
            ['name' => 'Queso Cheddar', 'cat' => $perecedero->id, 'unit' => 'kg', 'stock' => 3, 'min' => 1, 'cost' => 40],
            ['name' => 'Tomate', 'cat' => $perecedero->id, 'unit' => 'kg', 'stock' => 8, 'min' => 3, 'cost' => 8],
            ['name' => 'Tortillas Nachos', 'cat' => $noPerecedero->id, 'unit' => 'paquete', 'stock' => 20, 'min' => 5, 'cost' => 8],
        ];

        foreach ($ingredientes as $ing) {
            Ingrediente::create([
                'nombre' => $ing['name'],
                'categoria_id' => $ing['cat'],
                'unidad_medida' => $ing['unit'],
                'stock_actual' => $ing['stock'],
                'stock_minimo' => $ing['min'],
                'costo_unitario' => $ing['cost'],
                'fecha_vencimiento' => now()->addDays(30),
            ]);
        }

        // Receta Ejemplo: Nachos Supreme
        $nachos = Producto::where('nombre', 'Nachos Supreme')->first();
        $tortillas = Ingrediente::where('nombre', 'Tortillas Nachos')->first();
        $queso = Ingrediente::where('nombre', 'Queso Cheddar')->first();
        $tomate = Ingrediente::where('nombre', 'Tomate')->first();

        if ($nachos && $tortillas && $queso && $tomate) {
            $nachos->ingredientes()->attach([
                $tortillas->id => ['cantidad_necesaria' => 0.15],
                $queso->id => ['cantidad_necesaria' => 0.08],
                $tomate->id => ['cantidad_necesaria' => 0.05],
            ]);
        }
    }
}

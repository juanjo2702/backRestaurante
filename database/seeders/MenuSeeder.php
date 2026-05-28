<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;
use App\Models\Producto;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // Categorías
        $categorias = [
            ['nombre' => 'entradas', 'tipo' => 'menu'],
            ['nombre' => 'platos', 'tipo' => 'menu'],
            ['nombre' => 'bebidas', 'tipo' => 'menu'],
            ['nombre' => 'postres', 'tipo' => 'menu'],
        ];

        foreach ($categorias as $cat) {
            Categoria::firstOrCreate(['nombre' => $cat['nombre']], $cat);
        }

        // Productos
        $products = [
            // Entradas
            ['name' => 'Nachos Supreme', 'category' => 'entradas', 'price' => 12.50, 'image' => 'https://images.unsplash.com/photo-1513456852971-30c0b8199d4d?w=400&h=400&fit=crop'],
            ['name' => 'Ensalada César', 'category' => 'entradas', 'price' => 14.00, 'image' => 'https://images.unsplash.com/photo-1550304943-4f24f54ddde9?w=400&h=400&fit=crop'],
            ['name' => 'Sopa de Tomate', 'category' => 'entradas', 'price' => 8.50, 'image' => 'https://images.unsplash.com/photo-1547592166-23ac45744acd?w=400&h=400&fit=crop'],
            ['name' => 'Alitas BBQ', 'category' => 'entradas', 'price' => 18.00, 'image' => 'https://images.unsplash.com/photo-1567620832903-9fc6debc209f?w=400&h=400&fit=crop'],
            ['name' => 'Dip de Espinaca', 'category' => 'entradas', 'price' => 15.00, 'image' => 'https://images.unsplash.com/photo-1576458088443-04a19bb13da6?w=400&h=400&fit=crop'],

            // Platos
            ['name' => 'Filete Mignon', 'category' => 'platos', 'price' => 45.00, 'image' => 'https://images.unsplash.com/photo-1558030006-450675393462?w=400&h=400&fit=crop'],
            ['name' => 'Salmón Grillado', 'category' => 'platos', 'price' => 38.00, 'image' => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=400&h=400&fit=crop'],
            ['name' => 'Pasta Alfredo', 'category' => 'platos', 'price' => 24.00, 'image' => 'https://images.unsplash.com/photo-1645112411341-6c4fd023714a?w=400&h=400&fit=crop'],
            ['name' => 'Hamburguesa Clásica', 'category' => 'platos', 'price' => 20.00, 'image' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400&h=400&fit=crop'],
            ['name' => 'Risotto de Setas', 'category' => 'platos', 'price' => 28.00, 'image' => 'https://images.unsplash.com/photo-1476124369491-e7addf5db371?w=400&h=400&fit=crop'],

            // Bebidas
            ['name' => 'Limonada Fresca', 'category' => 'bebidas', 'price' => 6.00, 'image' => 'https://images.unsplash.com/photo-1621263764928-df1444c5e859?w=400&h=400&fit=crop'],
            ['name' => 'Coca-Cola', 'category' => 'bebidas', 'price' => 5.00, 'image' => 'https://images.unsplash.com/photo-1554866585-cd94860890b7?w=400&h=400&fit=crop'],
            ['name' => 'Mojito', 'category' => 'bebidas', 'price' => 12.00, 'image' => 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=400&h=400&fit=crop'],
            ['name' => 'Café Americano', 'category' => 'bebidas', 'price' => 8.00, 'image' => 'https://images.unsplash.com/photo-1559496417-e7f25cb247f3?w=400&h=400&fit=crop'],

            // Postres
            ['name' => 'Cheesecake', 'category' => 'postres', 'price' => 12.00, 'image' => 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=400&h=400&fit=crop'],
            ['name' => 'Brownie con Helado', 'category' => 'postres', 'price' => 14.00, 'image' => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400&h=400&fit=crop'],
            ['name' => 'Tiramisú', 'category' => 'postres', 'price' => 16.00, 'image' => 'https://images.unsplash.com/photo-1571875257727-256c39da42af?w=400&h=400&fit=crop'],
        ];

        foreach ($products as $prod) {
            $cat = Categoria::where('nombre', $prod['category'])->first();
            Producto::Create([
                'nombre' => $prod['name'],
                'categoria_id' => $cat->id,
                'precio' => $prod['price'],
                'imagen_url' => $prod['image'],
                'descripcion' => 'Delicioso ' . $prod['name'] . ' preparado con los mejores ingredientes.',
                'disponible' => true
            ]);
        }
    }
}

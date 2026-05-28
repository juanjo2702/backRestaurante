<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BoliviaRestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Rol::query()->get()->keyBy('nombre');

        $users = [
            ['nombre' => 'Carla Andrade', 'email' => 'admin@gusto.bo', 'rol' => 'admin'],
            ['nombre' => 'Luis Quispe', 'email' => 'mesero1@gusto.bo', 'rol' => 'waiter'],
            ['nombre' => 'María Flores', 'email' => 'mesero2@gusto.bo', 'rol' => 'waiter'],
            ['nombre' => 'Chef Ramiro', 'email' => 'cocina@gusto.bo', 'rol' => 'kitchen'],
            ['nombre' => 'Natalia Rojas', 'email' => 'caja@gusto.bo', 'rol' => 'cashier'],
            ['nombre' => 'Cliente Demo', 'email' => 'cliente@gusto.bo', 'rol' => 'client'],
        ];

        foreach ($users as $userData) {
            $role = $roles->get($userData['rol']) ?: Rol::query()->where('nombre', $userData['rol'])->first();

            if (!$role) {
                throw new \RuntimeException("Rol demo faltante: {$userData['rol']}");
            }

            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'nombre' => $userData['nombre'],
                    'password' => 'Demo12345!',
                    'rol_id' => $role->id,
                    'estado' => 'activo',
                    'email_verified_at' => now(),
                    'remember_token' => Str::random(10),
                ]
            );
        }

        $tableLayout = [
            ['numero' => 1, 'capacidad' => 2, 'x' => 10, 'y' => 10],
            ['numero' => 2, 'capacidad' => 2, 'x' => 28, 'y' => 10],
            ['numero' => 3, 'capacidad' => 4, 'x' => 46, 'y' => 10],
            ['numero' => 4, 'capacidad' => 4, 'x' => 64, 'y' => 10],
            ['numero' => 5, 'capacidad' => 6, 'x' => 82, 'y' => 10],
            ['numero' => 6, 'capacidad' => 2, 'x' => 10, 'y' => 34],
            ['numero' => 7, 'capacidad' => 2, 'x' => 28, 'y' => 34],
            ['numero' => 8, 'capacidad' => 4, 'x' => 46, 'y' => 34],
            ['numero' => 9, 'capacidad' => 4, 'x' => 64, 'y' => 34],
            ['numero' => 10, 'capacidad' => 8, 'x' => 82, 'y' => 34],
            ['numero' => 11, 'capacidad' => 2, 'x' => 10, 'y' => 58],
            ['numero' => 12, 'capacidad' => 4, 'x' => 28, 'y' => 58],
            ['numero' => 13, 'capacidad' => 4, 'x' => 46, 'y' => 58],
            ['numero' => 14, 'capacidad' => 6, 'x' => 64, 'y' => 58],
            ['numero' => 15, 'capacidad' => 6, 'x' => 82, 'y' => 58],
            ['numero' => 16, 'capacidad' => 8, 'x' => 82, 'y' => 82],
        ];

        $appKey = config('app.key') ?: env('APP_KEY', 'restaurant-local-key');

        foreach ($tableLayout as $table) {
            $mesa = Mesa::firstOrNew(['numero' => $table['numero']]);
            $uuid = $mesa->uuid ?: (string) Str::uuid();

            $mesa->fill([
                'uuid' => $uuid,
                'qr_signature' => hash_hmac('sha256', $uuid, $appKey),
                'is_qr_enabled' => true,
                'capacidad' => $table['capacidad'],
                'estado' => 'libre',
                'ubicacion_x' => $table['x'],
                'ubicacion_y' => $table['y'],
            ]);

            $mesa->save();
        }

        $categories = [
            ['nombre' => 'Carnes', 'tipo' => 'inventario', 'icono' => '🥩'],
            ['nombre' => 'Verduras', 'tipo' => 'inventario', 'icono' => '🥬'],
            ['nombre' => 'Lácteos', 'tipo' => 'inventario', 'icono' => '🧀'],
            ['nombre' => 'Abarrotes', 'tipo' => 'inventario', 'icono' => '📦'],
            ['nombre' => 'Bebidas e Insumos', 'tipo' => 'inventario', 'icono' => '🥤'],
            ['nombre' => 'Entradas', 'tipo' => 'menu', 'icono' => '🥗'],
            ['nombre' => 'Platos Fuertes', 'tipo' => 'menu', 'icono' => '🍽️'],
            ['nombre' => 'Bebidas', 'tipo' => 'menu', 'icono' => '🍹'],
            ['nombre' => 'Postres', 'tipo' => 'menu', 'icono' => '🍨'],
        ];

        $categoryMap = [];
        foreach ($categories as $category) {
            $categoryMap[$category['nombre']] = Categoria::updateOrCreate(
                ['nombre' => $category['nombre'], 'tipo' => $category['tipo']],
                ['icono' => $category['icono'], 'activo' => true]
            );
        }

        $ingredients = [
            ['nombre' => 'Carne de res', 'categoria' => 'Carnes', 'unidad' => 'kg', 'stock' => 45, 'min' => 8, 'cost' => 52, 'icono' => '🥩'],
            ['nombre' => 'Pecho de pollo', 'categoria' => 'Carnes', 'unidad' => 'kg', 'stock' => 28, 'min' => 6, 'cost' => 24, 'icono' => '🍗'],
            ['nombre' => 'Corazón de res', 'categoria' => 'Carnes', 'unidad' => 'kg', 'stock' => 14, 'min' => 4, 'cost' => 38, 'icono' => '🍢'],
            ['nombre' => 'Papa', 'categoria' => 'Verduras', 'unidad' => 'kg', 'stock' => 110, 'min' => 20, 'cost' => 5.5, 'icono' => '🥔'],
            ['nombre' => 'Tomate', 'categoria' => 'Verduras', 'unidad' => 'kg', 'stock' => 32, 'min' => 7, 'cost' => 7, 'icono' => '🍅'],
            ['nombre' => 'Cebolla', 'categoria' => 'Verduras', 'unidad' => 'kg', 'stock' => 26, 'min' => 6, 'cost' => 4.2, 'icono' => '🧅'],
            ['nombre' => 'Locoto', 'categoria' => 'Verduras', 'unidad' => 'kg', 'stock' => 8, 'min' => 2, 'cost' => 11, 'icono' => '🌶️'],
            ['nombre' => 'Lechuga', 'categoria' => 'Verduras', 'unidad' => 'unidad', 'stock' => 20, 'min' => 5, 'cost' => 3.5, 'icono' => '🥬'],
            ['nombre' => 'Maní molido', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 18, 'min' => 5, 'cost' => 16, 'icono' => '🥜'],
            ['nombre' => 'Fideo', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 30, 'min' => 6, 'cost' => 9, 'icono' => '🍜'],
            ['nombre' => 'Pan molido', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 12, 'min' => 3, 'cost' => 8, 'icono' => '🍞'],
            ['nombre' => 'Arroz', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 34, 'min' => 6, 'cost' => 8.5, 'icono' => '🍚'],
            ['nombre' => 'Harina', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 25, 'min' => 4, 'cost' => 7.5, 'icono' => '🌾'],
            ['nombre' => 'Azúcar', 'categoria' => 'Abarrotes', 'unidad' => 'kg', 'stock' => 22, 'min' => 4, 'cost' => 6, 'icono' => '🍚'],
            ['nombre' => 'Queso', 'categoria' => 'Lácteos', 'unidad' => 'kg', 'stock' => 14, 'min' => 4, 'cost' => 38, 'icono' => '🧀'],
            ['nombre' => 'Huevo', 'categoria' => 'Lácteos', 'unidad' => 'unidad', 'stock' => 160, 'min' => 24, 'cost' => 1.1, 'icono' => '🥚'],
            ['nombre' => 'Canela', 'categoria' => 'Bebidas e Insumos', 'unidad' => 'kg', 'stock' => 4, 'min' => 1, 'cost' => 58, 'icono' => '🧂'],
            ['nombre' => 'Maíz morado', 'categoria' => 'Bebidas e Insumos', 'unidad' => 'kg', 'stock' => 14, 'min' => 3, 'cost' => 9.5, 'icono' => '🌽'],
            ['nombre' => 'Cerveza Huari', 'categoria' => 'Bebidas e Insumos', 'unidad' => 'unidad', 'stock' => 48, 'min' => 12, 'cost' => 11, 'icono' => '🍺'],
            ['nombre' => 'Singani', 'categoria' => 'Bebidas e Insumos', 'unidad' => 'botella', 'stock' => 12, 'min' => 3, 'cost' => 48, 'icono' => '🍾'],
        ];

        $ingredientMap = [];
        foreach ($ingredients as $ingredient) {
            $ingredientMap[$ingredient['nombre']] = Ingrediente::updateOrCreate(
                ['nombre' => $ingredient['nombre']],
                [
                    'categoria_id' => $categoryMap[$ingredient['categoria']]->id,
                    'unidad_medida' => $ingredient['unidad'],
                    'stock_actual' => $ingredient['stock'],
                    'stock_minimo' => $ingredient['min'],
                    'costo_unitario' => $ingredient['cost'],
                    'fecha_vencimiento' => now()->addDays(rand(5, 45)),
                    'icono' => $ingredient['icono'],
                ]
            );
        }

        $products = [
            ['nombre' => 'Anticucho', 'categoria' => 'Entradas', 'precio' => 24, 'descripcion' => 'Corazón de res, papa y llajua.', 'icon' => '🍢', 'recipe' => ['Corazón de res' => 0.22, 'Papa' => 0.18, 'Locoto' => 0.01]],
            ['nombre' => 'Salteña de Carne', 'categoria' => 'Entradas', 'precio' => 12, 'descripcion' => 'Salteña horneada de carne con caldo especiado.', 'icon' => '🥟', 'recipe' => ['Carne de res' => 0.08, 'Papa' => 0.03, 'Harina' => 0.05, 'Cebolla' => 0.02]],
            ['nombre' => 'Pique Macho', 'categoria' => 'Platos Fuertes', 'precio' => 62, 'descripcion' => 'Pique macho cochabambino con carne, papa, huevo y locoto.', 'icon' => '🥩', 'recipe' => ['Carne de res' => 0.30, 'Papa' => 0.35, 'Tomate' => 0.08, 'Cebolla' => 0.08, 'Locoto' => 0.02, 'Huevo' => 1]],
            ['nombre' => 'Silpancho', 'categoria' => 'Platos Fuertes', 'precio' => 38, 'descripcion' => 'Tradicional silpancho con arroz, papa y huevo.', 'icon' => '🍽️', 'recipe' => ['Carne de res' => 0.18, 'Arroz' => 0.12, 'Papa' => 0.22, 'Huevo' => 1, 'Pan molido' => 0.04]],
            ['nombre' => 'Sopa de Maní', 'categoria' => 'Platos Fuertes', 'precio' => 28, 'descripcion' => 'Sopa cremosa con maní molido y fideo.', 'icon' => '🥣', 'recipe' => ['Maní molido' => 0.08, 'Fideo' => 0.06, 'Pecho de pollo' => 0.10, 'Papa' => 0.12]],
            ['nombre' => 'Api Morado', 'categoria' => 'Bebidas', 'precio' => 10, 'descripcion' => 'Bebida caliente tradicional andina.', 'icon' => '🫖', 'recipe' => ['Maíz morado' => 0.08, 'Azúcar' => 0.03, 'Canela' => 0.005]],
            ['nombre' => 'Mocochinchi', 'categoria' => 'Bebidas', 'precio' => 9, 'descripcion' => 'Refresco tradicional de durazno deshidratado.', 'icon' => '🥤', 'recipe' => ['Azúcar' => 0.02, 'Canela' => 0.003]],
            ['nombre' => 'Chicha Boliviana', 'categoria' => 'Bebidas', 'precio' => 14, 'descripcion' => 'Bebida típica servida fría.', 'icon' => '🍹', 'recipe' => ['Maíz morado' => 0.06, 'Azúcar' => 0.03]],
            ['nombre' => 'Huari Helada', 'categoria' => 'Bebidas', 'precio' => 18, 'descripcion' => 'Cerveza nacional premium.', 'icon' => '🍺', 'recipe' => ['Cerveza Huari' => 1]],
            ['nombre' => 'Helado de Canela', 'categoria' => 'Postres', 'precio' => 15, 'descripcion' => 'Helado artesanal perfumado con canela.', 'icon' => '🍨', 'recipe' => ['Canela' => 0.003, 'Azúcar' => 0.03, 'Lechuga' => 0]],
        ];

        foreach ($products as $productData) {
            $product = Producto::updateOrCreate(
                ['nombre' => $productData['nombre']],
                [
                    'categoria_id' => $categoryMap[$productData['categoria']]->id,
                    'precio' => $productData['precio'],
                    'descripcion' => $productData['descripcion'],
                    'imagen_url' => $this->menuPlaceholderPath($productData['nombre']),
                    'disponible' => true,
                ]
            );

            $syncData = [];
            foreach ($productData['recipe'] as $ingredientName => $quantity) {
                if ($quantity <= 0 || !isset($ingredientMap[$ingredientName])) {
                    continue;
                }

                $syncData[$ingredientMap[$ingredientName]->id] = ['cantidad_necesaria' => $quantity];
            }
            $product->ingredientes()->sync($syncData);
        }
    }

    private function menuPlaceholderPath(string $label): string
    {
        return '/seed/menu/' . Str::slug($label) . '.svg';
    }
}

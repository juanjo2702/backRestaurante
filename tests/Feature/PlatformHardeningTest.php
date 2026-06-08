<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_login_endpoint_authenticates_successfully(): void
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        User::create([
            'nombre' => 'Admin Demo',
            'email' => 'admin@example.com',
            'password' => bcrypt('Demo12345!'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Demo12345!',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user' => ['id', 'nombre', 'email', 'rol'],
            ]);
    }

    public function test_login_is_rate_limited_after_repeated_invalid_attempts(): void
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        User::create([
            'nombre' => 'Admin Demo',
            'email' => 'limit@example.com',
            'password' => bcrypt('Demo12345!'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'limit@example.com',
                'password' => 'incorrecta',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'limit@example.com',
            'password' => 'incorrecta',
        ])->assertStatus(429);
    }

    public function test_reports_and_customer_analytics_work_for_mysql_safe_queries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 18, 13, 0, 0));

        [$user, $product] = $this->createAdminUserWithMenuProduct();
        $mesa = $this->createMesa(10);

        $firstOrder = Pedido::create([
            'mesa_id' => $mesa->id,
            'usuario_id' => $user->id,
            'nombre_cliente' => 'Cliente Frecuente',
            'tipo_pedido' => 'mesa',
            'estado' => 'pagado',
            'total' => 90,
            'created_at' => Carbon::now()->subDays(1),
            'updated_at' => Carbon::now()->subDays(1),
        ]);

        $secondOrder = Pedido::create([
            'mesa_id' => null,
            'usuario_id' => $user->id,
            'nombre_cliente' => 'Cliente Frecuente',
            'telefono_cliente' => '+59170000001',
            'tipo_pedido' => 'llevar',
            'estado' => 'servido',
            'total' => 45,
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        $guestOrder = Pedido::create([
            'mesa_id' => null,
            'usuario_id' => null,
            'nombre_cliente' => 'Cliente Ocasional',
            'telefono_cliente' => '+59170000002',
            'tipo_pedido' => 'llevar',
            'estado' => 'pagado',
            'total' => 30,
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);

        foreach ([
            [$firstOrder, 2, 90],
            [$secondOrder, 1, 45],
            [$guestOrder, 1, 30],
        ] as [$order, $quantity, $subtotal]) {
            DetallePedido::create([
                'pedido_id' => $order->id,
                'producto_id' => $product->id,
                'cantidad' => $quantity,
                'precio_unitario' => $subtotal / $quantity,
                'subtotal' => $subtotal,
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports?period=week')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => ['revenue', 'orders', 'avg_ticket', 'clients'],
                'changes',
                'chart',
                'top_products',
                'types',
                'peaks',
            ])
            ->assertJsonPath('stats.orders', 3);

        $this->getJson('/api/v1/customers/top?period=week')
            ->assertOk()
            ->assertJsonFragment([
                'customer_name' => 'Cliente Frecuente',
                'order_count' => 2,
            ]);

        $this->getJson('/api/v1/customers/retention')
            ->assertOk()
            ->assertJsonPath('total_customers', 2)
            ->assertJsonPath('repeat_customers', 1)
            ->assertJsonPath('retention_rate', 50);

        Carbon::setTestNow();
    }

    public function test_special_collection_routes_are_not_shadowed_by_resource_routes(): void
    {
        [$user] = $this->createAdminUserWithMenuProduct();
        Sanctum::actingAs($user);

        $inventoryCategory = Categoria::create([
            'nombre' => 'Insumos',
            'tipo' => 'inventario',
            'activo' => true,
        ]);

        Ingrediente::create([
            'nombre' => 'Papa',
            'categoria_id' => $inventoryCategory->id,
            'unidad_medida' => 'kg',
            'stock_actual' => 1,
            'stock_minimo' => 3,
            'costo_unitario' => 5,
            'fecha_vencimiento' => Carbon::now()->addDays(3),
        ]);

        $mesa = $this->createMesa(12);

        Reserva::create([
            'mesa_id' => $mesa->id,
            'nombre_cliente' => 'Reserva Demo',
            'cantidad_personas' => 4,
            'hora_reserva' => Carbon::now()->addHour(),
            'telefono' => '+59171111111',
            'estado' => 'pendiente',
        ]);

        $this->getJson('/api/v1/ingredients/low-stock')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Papa']);

        $this->getJson('/api/v1/ingredients/expiring')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Papa']);

        $this->getJson('/api/v1/reservations/active')
            ->assertOk()
            ->assertJsonFragment(['nombre_cliente' => 'Reserva Demo']);
    }

    public function test_legacy_and_versioned_tables_routes_return_equivalent_payloads(): void
    {
        [$user] = $this->createAdminUserWithMenuProduct();
        Sanctum::actingAs($user);

        $this->createMesa(7);

        $legacyResponse = $this->getJson('/api/tables')->assertOk();
        $versionedResponse = $this->getJson('/api/v1/tables')->assertOk();

        $this->assertEquals($legacyResponse->json(), $versionedResponse->json());
    }

    public function test_public_reservation_accepts_local_datetime_string_and_persists_successfully(): void
    {
        Storage::fake('public');
        $mesa = $this->createMesa(15);

        $response = $this->post('/api/public/reservations', [
            'mesa_id' => $mesa->id,
            'nombre_cliente' => 'Juan Jose Mamani Via',
            'cantidad_personas' => 2,
            'hora_reserva' => '2026-04-20 14:30:00',
            'telefono' => '67760520',
            'garantia_referencia' => 'TX-123',
            'comprobante_garantia' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('mesa.numero', 15)
            ->assertJsonPath('garantia_estado', 'pending_review');

        $this->assertDatabaseHas('reservas', [
            'mesa_id' => $mesa->id,
            'nombre_cliente' => 'Juan Jose Mamani Via',
            'telefono' => '67760520',
            'estado' => 'pendiente',
            'garantia_referencia' => 'TX-123',
        ]);
    }

    private function createAdminUserWithMenuProduct(): array
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        $user = User::create([
            'nombre' => 'Operador Reportes',
            'email' => 'reports@example.com',
            'password' => bcrypt('Demo12345!'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $category = Categoria::create([
            'nombre' => 'Platos',
            'tipo' => 'menu',
            'activo' => true,
        ]);

        $product = Producto::create([
            'nombre' => 'Silpancho',
            'categoria_id' => $category->id,
            'precio' => 45,
            'descripcion' => 'Producto demo',
            'disponible' => true,
        ]);

        return [$user, $product];
    }

    private function createMesa(int $number): Mesa
    {
        return Mesa::create([
            'uuid' => (string) Str::uuid(),
            'qr_signature' => hash('sha256', 'mesa-'.$number),
            'is_qr_enabled' => true,
            'numero' => $number,
            'capacidad' => 4,
            'estado' => 'libre',
        ]);
    }
}

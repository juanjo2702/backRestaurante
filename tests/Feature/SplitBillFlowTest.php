<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SplitBillFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initializes_split_bill_by_session_and_creates_common_account(): void
    {
        [$user, $mesa, $producto] = $this->createRestaurantSetup();

        $sessionOne = $this->createTableSession($mesa);
        $sessionTwo = $this->createTableSession($mesa);

        $this->createReadyOrder($mesa, $producto, 30, [
            'nombre_cliente' => 'Juan',
            'table_session_id' => $sessionOne->id,
            'order_source' => 'public_table',
        ]);

        $this->createReadyOrder($mesa, $producto, 25, [
            'nombre_cliente' => 'Maria',
            'table_session_id' => $sessionTwo->id,
            'order_source' => 'public_table',
        ]);

        $this->createReadyOrder($mesa, $producto, 20, [
            'nombre_cliente' => 'Pedido salon',
            'table_session_id' => null,
            'order_source' => 'staff',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tables/{$mesa->id}/split-bill/initialize", [
            'strategy' => 'by_session',
        ])->assertOk();

        $response
            ->assertJsonPath('data.bill.total_amount', 75)
            ->assertJsonCount(3, 'data.accounts');

        $this->assertDatabaseHas('bill_accounts', [
            'mesa_bill_id' => $response->json('data.bill.id'),
            'display_name' => 'Cuenta comun',
            'owner_type' => 'manual',
        ]);

        $this->assertDatabaseHas('bill_accounts', [
            'mesa_bill_id' => $response->json('data.bill.id'),
            'display_name' => 'Juan',
            'owner_type' => 'session',
        ]);
    }

    public function test_it_supports_manual_split_and_partial_account_payment_before_settling_the_table(): void
    {
        [$user, $mesa, $producto] = $this->createRestaurantSetup();

        $sessionOne = $this->createTableSession($mesa);
        $sessionTwo = $this->createTableSession($mesa);

        $firstOrder = $this->createReadyOrder($mesa, $producto, 30, [
            'nombre_cliente' => 'Juan',
            'table_session_id' => $sessionOne->id,
            'order_source' => 'public_table',
        ]);

        $this->createReadyOrder($mesa, $producto, 30, [
            'nombre_cliente' => 'Maria',
            'table_session_id' => $sessionTwo->id,
            'order_source' => 'public_table',
        ]);

        Sanctum::actingAs($user);

        $initialized = $this->postJson("/api/v1/tables/{$mesa->id}/split-bill/initialize", [
            'strategy' => 'by_session',
        ])->assertOk()->json('data');

        $extraAccount = $this->postJson("/api/v1/tables/{$mesa->id}/split-bill/accounts", [
            'display_name' => 'Invitado extra',
        ])->assertCreated()->json('data');

        $juanAccount = collect($extraAccount['accounts'])->firstWhere('display_name', 'Juan');
        $guestAccount = collect($extraAccount['accounts'])->firstWhere('display_name', 'Invitado extra');
        $juanLine = collect($extraAccount['line_items'])->firstWhere('account_display_name', 'Juan');

        $this->postJson("/api/v1/tables/{$mesa->id}/split-bill/allocations", [
            'action' => 'split',
            'allocation_id' => $juanLine['allocation_id'],
            'target_account_id' => $guestAccount['id'],
            'amount' => 10,
        ])->assertOk();

        $this->assertEquals(30.0, (float) DetallePedido::where('pedido_id', $firstOrder->id)->sum('subtotal'));
        $this->assertEquals(
            30.0,
            (float) \App\Models\BillAccountAllocation::where('detalle_pedido_id', $juanLine['detail_id'])->sum('allocated_amount')
        );

        $paymentId = $this->postJson('/api/v1/payments/intents', [
            'bill_account_id' => $juanAccount['id'],
            'method' => 'cash',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/payments/{$paymentId}/confirm")
            ->assertOk();

        $this->assertDatabaseHas('bill_accounts', [
            'id' => $juanAccount['id'],
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'ocupada',
        ]);

        $wholePaymentId = $this->postJson('/api/v1/payments/intents', [
            'mesa_id' => $mesa->id,
            'method' => 'cash',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/payments/{$wholePaymentId}/confirm")
            ->assertOk();

        $this->assertDatabaseHas('mesa_bills', [
            'id' => $initialized['bill']['id'],
            'status' => 'settled',
            'outstanding_amount' => 0,
        ]);

        $this->assertDatabaseHas('pedidos', [
            'id' => $firstOrder->id,
            'estado' => 'pagado',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'libre',
        ]);
    }

    private function createRestaurantSetup(): array
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        $user = User::create([
            'nombre' => 'Admin Split',
            'email' => 'split@example.com',
            'password' => bcrypt('password'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $mesa = Mesa::create([
            'uuid' => (string) Str::uuid(),
            'qr_signature' => hash('sha256', 'mesa-split'),
            'is_qr_enabled' => true,
            'numero' => 5,
            'capacidad' => 4,
            'estado' => 'ocupada',
        ]);

        $category = Categoria::create([
            'nombre' => 'Platos',
            'tipo' => 'menu',
            'activo' => true,
        ]);

        $producto = Producto::create([
            'nombre' => 'Pique macho split',
            'categoria_id' => $category->id,
            'precio' => 30,
            'disponible' => true,
        ]);

        return [$user, $mesa, $producto];
    }

    private function createTableSession(Mesa $mesa): TableSession
    {
        return TableSession::create([
            'mesa_id' => $mesa->id,
            'session_token_hash' => hash('sha256', Str::random(40)),
            'status' => 'active',
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(2),
            'last_seen_at' => Carbon::now(),
            'client_fingerprint' => Str::uuid()->toString(),
            'ip_address' => '127.0.0.1',
        ]);
    }

    private function createReadyOrder(Mesa $mesa, Producto $producto, float $subtotal, array $overrides = []): Pedido
    {
        $pedido = Pedido::create(array_merge([
            'mesa_id' => $mesa->id,
            'usuario_id' => null,
            'table_session_id' => null,
            'order_source' => 'staff',
            'nombre_cliente' => null,
            'telefono_cliente' => '+59170000000',
            'tipo_pedido' => 'mesa',
            'estado' => 'listo',
            'total' => $subtotal,
        ], $overrides));

        DetallePedido::create([
            'pedido_id' => $pedido->id,
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'precio_unitario' => $subtotal,
            'subtotal' => $subtotal,
        ]);

        return $pedido;
    }
}

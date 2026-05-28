<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_confirms_a_table_payment_from_a_ready_order(): void
    {
        [$user, $mesa, $producto, $ingrediente] = $this->createRestaurantSetup();

        $pedido = Pedido::create([
            'mesa_id' => $mesa->id,
            'usuario_id' => $user->id,
            'nombre_cliente' => 'Cliente Bolivia',
            'telefono_cliente' => '+59170000001',
            'tipo_pedido' => 'mesa',
            'estado' => 'listo',
            'total' => 50,
        ]);

        DetallePedido::create([
            'pedido_id' => $pedido->id,
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'precio_unitario' => 25,
            'subtotal' => 50,
        ]);

        $paymentService = app(PaymentService::class);

        $payment = $paymentService->createIntentForTable($mesa, 'cash', $user);
        $paymentService->confirm($payment, $user);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $payment->id,
            'status' => 'confirmed',
            'method' => 'cash',
            'amount' => 50,
        ]);

        $this->assertDatabaseHas('pedidos', [
            'id' => $pedido->id,
            'estado' => 'pagado',
            'metodo_pago' => 'cash',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'libre',
            'pago_pendiente_monto' => null,
            'pago_pendiente_metodo' => null,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'pedido_id' => $pedido->id,
            'to_status' => 'pagado',
        ]);

        $this->assertDatabaseHas('loyalty_points', [
            'customer_phone' => '+59170000001',
            'points' => 5,
        ]);

        $this->assertSame(10.0, (float) $ingrediente->fresh()->stock_actual);
    }

    public function test_it_consumes_and_restores_inventory_across_order_transitions(): void
    {
        [$user, $mesa, $producto, $ingrediente] = $this->createRestaurantSetup();

        $pedido = Pedido::create([
            'mesa_id' => $mesa->id,
            'usuario_id' => $user->id,
            'tipo_pedido' => 'mesa',
            'estado' => 'pendiente',
            'total' => 75,
        ]);

        DetallePedido::create([
            'pedido_id' => $pedido->id,
            'producto_id' => $producto->id,
            'cantidad' => 3,
            'precio_unitario' => 25,
            'subtotal' => 75,
        ]);

        $lifecycle = app(OrderLifecycleService::class);

        $lifecycle->transition($pedido->load('detalles.producto.ingredientes', 'mesa'), 'preparando', $user);
        $lifecycle->transition($pedido->fresh()->load('detalles.producto.ingredientes', 'mesa'), 'listo', $user);

        $this->assertDatabaseHas('inventory_movements', [
            'ingrediente_id' => $ingrediente->id,
            'type' => 'consumption',
            'quantity' => 3,
            'stock_after' => 7,
        ]);

        $this->assertSame(7.0, (float) $ingrediente->fresh()->stock_actual);

        $lifecycle->transition($pedido->fresh()->load('detalles.producto.ingredientes', 'mesa'), 'cancelado', $user);

        $this->assertDatabaseHas('inventory_movements', [
            'ingrediente_id' => $ingrediente->id,
            'type' => 'reversal',
            'quantity' => 3,
            'stock_after' => 10,
        ]);

        $this->assertSame(10.0, (float) $ingrediente->fresh()->stock_actual);
    }

    public function test_invalid_transition_returns_a_validation_error(): void
    {
        [$user, $mesa] = $this->createRestaurantSetup();

        $pedido = Pedido::create([
            'mesa_id' => $mesa->id,
            'usuario_id' => $user->id,
            'tipo_pedido' => 'mesa',
            'estado' => 'pendiente',
            'total' => 10,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/orders/{$pedido->id}", [
            'estado' => 'pagado',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Transición inválida de pendiente a pagado',
            ]);
    }

    private function createRestaurantSetup(): array
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        $user = User::create([
            'nombre' => 'Administrador',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $mesa = Mesa::create([
            'numero' => 1,
            'capacidad' => 4,
            'estado' => 'ocupada',
        ]);

        $menuCategory = Categoria::create([
            'nombre' => 'Platos',
            'tipo' => 'menu',
            'activo' => true,
        ]);

        $inventoryCategory = Categoria::create([
            'nombre' => 'Proteínas',
            'tipo' => 'inventario',
            'activo' => true,
        ]);

        $ingrediente = Ingrediente::create([
            'nombre' => 'Carne',
            'categoria_id' => $inventoryCategory->id,
            'unidad_medida' => 'unidad',
            'stock_actual' => 10,
            'stock_minimo' => 2,
            'costo_unitario' => 12,
        ]);

        $producto = Producto::create([
            'nombre' => 'Silpancho',
            'categoria_id' => $menuCategory->id,
            'precio' => 25,
            'disponible' => true,
        ]);

        $producto->ingredientes()->attach($ingrediente->id, ['cantidad_necesaria' => 1]);

        return [$user, $mesa, $producto, $ingrediente];
    }
}

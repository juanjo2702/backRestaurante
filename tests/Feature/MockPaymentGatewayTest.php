<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\DetallePedido;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\PaymentGatewayAttempt;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MockPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_mock_checkout_confirms_payment_and_frees_the_table(): void
    {
        [$user, $mesa, $pedido] = $this->createReadyTableOrder();

        Sanctum::actingAs($user);

        $paymentId = $this->postJson('/api/v1/payments/intents', [
            'mesa_id' => $mesa->id,
            'method' => 'card',
        ])->assertCreated()->json('id');

        $session = $this->postJson("/api/v1/payments/{$paymentId}/mock-checkout/session")
            ->assertOk()
            ->json();

        $response = $this->postJson('/api/v1/payments/mock-checkout/submit', [
            'checkout_token' => $session['checkout_token'],
            'cardholder_name' => 'Cliente Demo',
            'pan' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2031',
            'cvv' => '123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('outcome', 'success');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $paymentId,
            'status' => 'confirmed',
            'method' => 'card',
        ]);

        $this->assertDatabaseHas('pedidos', [
            'id' => $pedido->id,
            'estado' => 'pagado',
            'metodo_pago' => 'card',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'libre',
        ]);

        $this->assertDatabaseHas('payment_gateway_attempts', [
            'payment_transaction_id' => $paymentId,
            'stage' => 'capture',
            'outcome' => 'success',
            'card_last4' => '1111',
        ]);

        $attempt = PaymentGatewayAttempt::where('payment_transaction_id', $paymentId)
            ->where('stage', 'authorization')
            ->firstOrFail();

        $this->assertArrayNotHasKey('pan', $attempt->response_payload);
        $this->assertArrayNotHasKey('cvv', $attempt->response_payload);
    }

    public function test_declined_mock_checkout_keeps_payment_pending_and_is_not_retryable(): void
    {
        [$user, $mesa] = $this->createReadyTableOrder('declined@example.com');

        Sanctum::actingAs($user);

        $paymentId = $this->postJson('/api/v1/payments/intents', [
            'mesa_id' => $mesa->id,
            'method' => 'card',
        ])->assertCreated()->json('id');

        $session = $this->postJson("/api/v1/payments/{$paymentId}/mock-checkout/session")
            ->assertOk()
            ->json();

        $this->postJson('/api/v1/payments/mock-checkout/submit', [
            'checkout_token' => $session['checkout_token'],
            'cardholder_name' => 'Cliente Rechazado',
            'pan' => '4000000000000002',
            'expiry_month' => '11',
            'expiry_year' => '2030',
            'cvv' => '999',
        ])
            ->assertOk()
            ->assertJsonPath('outcome', 'declined')
            ->assertJsonPath('retryable', false);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $paymentId,
            'status' => 'pending',
        ]);
    }

    public function test_timeout_is_retryable_and_checkout_token_cannot_be_reused(): void
    {
        [$user, $mesa] = $this->createReadyTableOrder('timeout@example.com');

        Sanctum::actingAs($user);

        $paymentId = $this->postJson('/api/v1/payments/intents', [
            'mesa_id' => $mesa->id,
            'method' => 'card',
        ])->assertCreated()->json('id');

        $session = $this->postJson("/api/v1/payments/{$paymentId}/mock-checkout/session")
            ->assertOk()
            ->json();

        $payload = [
            'checkout_token' => $session['checkout_token'],
            'cardholder_name' => 'Cliente Timeout',
            'pan' => '4000000000000127',
            'expiry_month' => '08',
            'expiry_year' => '2032',
            'cvv' => '321',
        ];

        $this->postJson('/api/v1/payments/mock-checkout/submit', $payload)
            ->assertOk()
            ->assertJsonPath('outcome', 'timeout')
            ->assertJsonPath('retryable', true);

        $this->postJson('/api/v1/payments/mock-checkout/submit', $payload)
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'La sesion de checkout expiro o ya fue usada',
            ]);
    }

    private function createReadyTableOrder(string $email = 'mock-admin@example.com'): array
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        $user = User::create([
            'nombre' => 'Operador Mock',
            'email' => $email,
            'password' => bcrypt('password'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $mesa = Mesa::create([
            'numero' => 4,
            'capacidad' => 4,
            'estado' => 'ocupada',
        ]);

        $menuCategory = Categoria::create([
            'nombre' => 'Platos',
            'tipo' => 'menu',
            'activo' => true,
        ]);

        $inventoryCategory = Categoria::create([
            'nombre' => 'Insumos',
            'tipo' => 'inventario',
            'activo' => true,
        ]);

        $ingrediente = Ingrediente::create([
            'nombre' => 'Carne mock',
            'categoria_id' => $inventoryCategory->id,
            'unidad_medida' => 'kg',
            'stock_actual' => 10,
            'stock_minimo' => 2,
            'costo_unitario' => 20,
        ]);

        $producto = Producto::create([
            'nombre' => 'Silpancho mock',
            'categoria_id' => $menuCategory->id,
            'precio' => 45,
            'disponible' => true,
        ]);

        $producto->ingredientes()->attach($ingrediente->id, ['cantidad_necesaria' => 1]);

        $pedido = Pedido::create([
            'mesa_id' => $mesa->id,
            'usuario_id' => $user->id,
            'nombre_cliente' => 'Cliente Mock',
            'telefono_cliente' => '+59170000111',
            'tipo_pedido' => 'mesa',
            'estado' => 'listo',
            'total' => 45,
        ]);

        DetallePedido::create([
            'pedido_id' => $pedido->id,
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'precio_unitario' => 45,
            'subtotal' => 45,
        ]);

        return [$user, $mesa, $pedido];
    }
}

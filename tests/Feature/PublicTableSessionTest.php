<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTableSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_public_table_session_with_a_valid_signature(): void
    {
        [$mesa] = $this->createPublicTableSetup();

        $response = $this->postPublicTableSession('10.10.0.1', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'demo-fingerprint',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('table.uuid', $mesa->uuid);

        $this->assertDatabaseHas('table_sessions', [
            'mesa_id' => $mesa->id,
            'status' => 'active',
            'client_fingerprint' => 'demo-fingerprint',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'libre',
        ]);
    }

    public function test_it_rejects_an_invalid_qr_signature(): void
    {
        [$mesa] = $this->createPublicTableSetup();

        $this->postPublicTableSession('10.10.0.2', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => 'firma-invalida',
            'fingerprint' => 'demo-fingerprint',
        ])->assertStatus(403);
    }

    public function test_it_creates_a_public_order_only_with_a_valid_active_session(): void
    {
        [$mesa, $producto] = $this->createPublicTableSetup();

        $sessionResponse = $this->postPublicTableSession('10.10.0.3', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'demo-fingerprint',
        ]);

        $token = $sessionResponse->json('table_session_token');

        $this->postJson(
            "/api/public/tables/{$mesa->uuid}/orders",
            [
                'customer_name' => 'Mesa QR',
                'items' => [
                    ['product_id' => $producto->id, 'quantity' => 2],
                ],
            ],
            ['X-Table-Session-Token' => $token]
        )
            ->assertCreated()
            ->assertJsonPath('items_count', 1);

        $this->assertDatabaseHas('pedidos', [
            'mesa_id' => $mesa->id,
            'tipo_pedido' => 'mesa',
            'estado' => 'pendiente',
            'total' => 50,
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'ocupada',
        ]);
    }

    public function test_it_allows_multiple_active_public_sessions_for_the_same_table(): void
    {
        [$mesa] = $this->createPublicTableSetup();

        $firstResponse = $this->postPublicTableSession('10.10.0.4', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'device-a',
        ]);

        $secondResponse = $this->postPublicTableSession('10.10.0.5', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'device-b',
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $this->assertDatabaseCount('table_sessions', 2);
        $this->assertSame(2, TableSession::where('mesa_id', $mesa->id)->where('status', 'active')->count());
    }

    public function test_an_expired_public_session_cannot_place_orders(): void
    {
        [$mesa, $producto] = $this->createPublicTableSetup();

        TableSession::create([
            'mesa_id' => $mesa->id,
            'session_token_hash' => hash('sha256', 'expired-token'),
            'status' => 'active',
            'started_at' => now()->subHours(4),
            'expires_at' => now()->subMinute(),
            'last_seen_at' => now()->subHour(),
            'client_fingerprint' => 'demo-fingerprint',
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson(
            "/api/public/tables/{$mesa->uuid}/orders",
            [
                'customer_name' => 'Mesa QR',
                'items' => [
                    ['product_id' => $producto->id, 'quantity' => 1],
                ],
            ],
            ['X-Table-Session-Token' => 'expired-token']
        )
            ->assertStatus(401)
            ->assertJsonFragment([
                'message' => 'La sesión pública de la mesa expiró',
            ]);
    }

    public function test_it_returns_session_orders_and_status_for_the_public_table_view(): void
    {
        [$mesa, $producto] = $this->createPublicTableSetup();

        $sessionResponse = $this->postPublicTableSession('10.10.0.6', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'demo-fingerprint',
        ]);

        $token = $sessionResponse->json('table_session_token');

        $orderResponse = $this->postJson(
            "/api/public/tables/{$mesa->uuid}/orders",
            [
                'customer_name' => 'Mesa QR',
                'items' => [
                    ['product_id' => $producto->id, 'quantity' => 1],
                ],
            ],
            ['X-Table-Session-Token' => $token]
        );

        $orderId = $orderResponse->json('id');

        $this->getJson(
            "/api/public/tables/{$mesa->uuid}",
            ['X-Table-Session-Token' => $token]
        )
            ->assertOk()
            ->assertJsonPath('session_orders.0.id', $orderId)
            ->assertJsonPath('session_orders.0.estado', 'pendiente')
            ->assertJsonPath('session_orders.0.detalles.0.producto.nombre', 'Salchipapa');
    }

    public function test_public_table_view_falls_back_to_qr_signature_when_session_token_is_stale(): void
    {
        [$mesa] = $this->createPublicTableSetup();

        TableSession::create([
            'mesa_id' => $mesa->id,
            'session_token_hash' => hash('sha256', 'closed-token'),
            'status' => 'closed',
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'last_seen_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinute(),
            'client_fingerprint' => 'demo-fingerprint',
            'ip_address' => '127.0.0.1',
        ]);

        $this->getJson(
            "/api/public/tables/{$mesa->uuid}?sig={$mesa->qr_signature}",
            ['X-Table-Session-Token' => 'closed-token']
        )
            ->assertOk()
            ->assertJsonPath('uuid', $mesa->uuid)
            ->assertJsonPath('session', null);
    }

    private function createPublicTableSetup(): array
    {
        $mesa = Mesa::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'qr_signature' => '',
            'is_qr_enabled' => true,
            'numero' => 7,
            'capacidad' => 4,
            'estado' => 'libre',
        ]);

        $mesa->update([
            'qr_signature' => hash_hmac('sha256', $mesa->uuid, config('app.key') ?: env('APP_KEY', 'restaurant-local-key')),
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
            'nombre' => 'Papa',
            'categoria_id' => $inventoryCategory->id,
            'unidad_medida' => 'unidad',
            'stock_actual' => 30,
            'stock_minimo' => 5,
            'costo_unitario' => 1.5,
        ]);

        $producto = Producto::create([
            'nombre' => 'Salchipapa',
            'categoria_id' => $menuCategory->id,
            'precio' => 25,
            'disponible' => true,
        ]);

        $producto->ingredientes()->attach($ingrediente->id, ['cantidad_necesaria' => 2]);

        return [$mesa, $producto];
    }

    private function postPublicTableSession(string $ipAddress, array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/public/table-sessions', $payload);
    }
}

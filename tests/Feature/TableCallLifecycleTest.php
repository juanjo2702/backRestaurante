<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableCallLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_call_can_be_acknowledged_and_resolved_by_waiter(): void
    {
        [$mesa, $waiter] = $this->createCallLifecycleSetup();

        $sessionResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.7'])->postJson('/api/public/table-sessions', [
            'mesa_uuid' => $mesa->uuid,
            'signature' => $mesa->qr_signature,
            'fingerprint' => 'call-flow-fingerprint',
        ]);

        $sessionResponse->assertCreated();

        $token = $sessionResponse->json('table_session_token');

        $this->postJson(
            "/api/public/tables/{$mesa->uuid}/call",
            ['tipo' => 'order'],
            ['X-Table-Session-Token' => $token]
        )
            ->assertOk()
            ->assertJsonPath('llamada_tipo', 'order')
            ->assertJsonPath('llamada_estado', 'pending');

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'llamada_tipo' => 'order',
            'llamada_estado' => 'pending',
            'llamada_atendida_por' => null,
        ]);

        Sanctum::actingAs($waiter);

        $this->postJson("/api/tables/{$mesa->id}/calls/acknowledge")
            ->assertOk()
            ->assertJsonPath('llamada_tipo', 'order')
            ->assertJsonPath('llamada_estado', 'acknowledged')
            ->assertJsonPath('llamada_atendida_por', $waiter->id);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'llamada_tipo' => 'order',
            'llamada_estado' => 'acknowledged',
            'llamada_atendida_por' => $waiter->id,
        ]);

        $this->postJson("/api/tables/{$mesa->id}/calls/resolve")
            ->assertOk()
            ->assertJsonPath('llamada_tipo', null)
            ->assertJsonPath('llamada_estado', null);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'llamada_tipo' => null,
            'llamada_estado' => null,
            'llamada_atendida_por' => null,
        ]);
    }

    private function createCallLifecycleSetup(): array
    {
        $waiterRole = Rol::create([
            'nombre' => 'waiter',
            'descripcion' => 'Mesero',
        ]);

        $waiter = User::create([
            'nombre' => 'Mesero Uno',
            'email' => 'mesero-call@example.com',
            'password' => bcrypt('password'),
            'rol_id' => $waiterRole->id,
            'estado' => 'activo',
        ]);

        $mesa = Mesa::create([
            'uuid' => (string) Str::uuid(),
            'qr_signature' => '',
            'is_qr_enabled' => true,
            'numero' => 12,
            'capacidad' => 4,
            'estado' => 'ocupada',
        ]);

        $mesa->update([
            'qr_signature' => hash_hmac('sha256', $mesa->uuid, config('app.key') ?: env('APP_KEY', 'restaurant-local-key')),
        ]);

        return [$mesa, $waiter];
    }
}

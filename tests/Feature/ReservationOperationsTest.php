<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_reservation_with_proof_is_available_in_history(): void
    {
        Storage::fake('public');
        $mesa = $this->createMesa(12);

        $createResponse = $this->post('/api/public/reservations', [
            'mesa_id' => $mesa->id,
            'nombre_cliente' => 'Juan Mamani',
            'cantidad_personas' => 2,
            'hora_reserva' => '2026-04-22 12:30:00',
            'telefono' => '+59167760520',
            'garantia_referencia' => 'TRX-990',
            'comprobante_garantia' => UploadedFile::fake()->image('garantia.jpg'),
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('mesa.numero', 12)
            ->assertJsonPath('garantia_estado', 'pending_review')
            ->assertJsonStructure([
                'id',
                'codigo_reserva',
                'tracking_token',
                'garantia_comprobante_url',
            ]);

        $historyResponse = $this->postJson('/api/public/reservations/history', [
            'tokens' => [$createResponse->json('tracking_token')],
        ]);

        $historyResponse
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.codigo_reserva', $createResponse->json('codigo_reserva'))
            ->assertJsonPath('items.0.garantia_referencia', 'TRX-990');
    }

    public function test_cashier_can_review_and_operate_reservations(): void
    {
        Storage::fake('public');

        $cashierRole = Rol::create([
            'nombre' => 'cashier',
            'descripcion' => 'Caja',
        ]);

        $cashier = User::create([
            'nombre' => 'Caja Demo',
            'email' => 'cashier@example.com',
            'password' => 'Demo12345!',
            'rol_id' => $cashierRole->id,
            'estado' => 'activo',
        ]);

        $mesa = $this->createMesa(6);

        $reserva = Reserva::create([
            'codigo_reserva' => 'RSV-TEST01',
            'mesa_id' => $mesa->id,
            'nombre_cliente' => 'Maria Rojas',
            'cantidad_personas' => 4,
            'hora_reserva' => now()->addHour(),
            'telefono' => '+59170000111',
            'estado' => 'pendiente',
            'origen' => 'public',
            'operational_status' => 'scheduled',
            'garantia_monto' => 200,
            'garantia_estado' => 'pending_review',
            'garantia_comprobante_disk' => 'public',
            'garantia_comprobante_path' => 'reservation-proofs/demo.jpg',
            'garantia_subida_at' => now(),
            'tracking_token' => Str::random(40),
        ]);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/reservations/review-queue')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.codigo_reserva', 'RSV-TEST01');

        $this->postJson("/api/v1/reservations/{$reserva->id}/review", [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('garantia_estado', 'approved')
            ->assertJsonPath('estado', 'confirmada');

        $this->postJson("/api/v1/reservations/{$reserva->id}/operational-status", [
            'status' => 'arrived',
        ])
            ->assertOk()
            ->assertJsonPath('operational_status', 'arrived');

        $this->postJson("/api/v1/reservations/{$reserva->id}/operational-status", [
            'status' => 'seated',
        ])
            ->assertOk()
            ->assertJsonPath('operational_status', 'seated');

        $this->assertDatabaseHas('reservas', [
            'id' => $reserva->id,
            'garantia_estado' => 'approved',
            'estado' => 'confirmada',
            'operational_status' => 'seated',
        ]);

        $this->assertDatabaseHas('mesas', [
            'id' => $mesa->id,
            'estado' => 'ocupada',
        ]);
    }

    private function createMesa(int $number): Mesa
    {
        return Mesa::create([
            'uuid' => (string) Str::uuid(),
            'qr_signature' => hash('sha256', 'mesa-'.$number),
            'is_qr_enabled' => true,
            'numero' => $number,
            'capacidad' => 6,
            'estado' => 'libre',
        ]);
    }
}

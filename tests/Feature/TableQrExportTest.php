<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableQrExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_a_single_svg_qr(): void
    {
        [$user, $mesa] = $this->createAuthenticatedMesa();

        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/tables/{$mesa->id}/qr.svg");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_it_downloads_bulk_exports_in_zip_and_pdf(): void
    {
        [$user] = $this->createAuthenticatedMesa();
        $secondUuid = (string) Str::uuid();
        $secondMesa = Mesa::create([
            'uuid' => $secondUuid,
            'qr_signature' => hash_hmac('sha256', $secondUuid, config('app.key') ?: 'restaurant-local-key'),
            'is_qr_enabled' => true,
            'numero' => 11,
            'capacidad' => 4,
            'estado' => 'libre',
        ]);

        Sanctum::actingAs($user);

        $zipResponse = $this->post('/api/v1/tables/qr/bulk-export', [
            'format' => 'zip_svg',
            'table_ids' => [$secondMesa->id],
        ]);

        $zipResponse->assertOk();
        $zipResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertNotEmpty($zipResponse->streamedContent());

        $pdfResponse = $this->post('/api/v1/tables/qr/bulk-export', [
            'format' => 'pdf',
            'table_ids' => [$secondMesa->id],
        ]);

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());
    }

    public function test_it_blocks_individual_export_for_disabled_qr_tables(): void
    {
        [$user, $mesa] = $this->createAuthenticatedMesa([
            'is_qr_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $this->get("/api/v1/tables/{$mesa->id}/qr.pdf")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'El QR de esta mesa esta deshabilitado']);
    }

    private function createAuthenticatedMesa(array $mesaOverrides = []): array
    {
        $role = Rol::create([
            'nombre' => 'admin',
            'descripcion' => 'Administrador',
        ]);

        $user = User::create([
            'nombre' => 'Admin QR',
            'email' => 'admin-qr@example.com',
            'password' => bcrypt('password'),
            'rol_id' => $role->id,
            'estado' => 'activo',
        ]);

        $uuid = (string) Str::uuid();
        $mesa = Mesa::create(array_merge([
            'uuid' => $uuid,
            'qr_signature' => hash_hmac('sha256', $uuid, config('app.key') ?: 'restaurant-local-key'),
            'is_qr_enabled' => true,
            'numero' => 10,
            'capacidad' => 4,
            'estado' => 'libre',
        ], $mesaOverrides));

        return [$user, $mesa];
    }
}

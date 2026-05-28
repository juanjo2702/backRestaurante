<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicTableSessionService
{
    public function createSession(Mesa $mesa, string $signature, ?string $fingerprint, string $ipAddress): array
    {
        $this->assertQrEnabled($mesa);
        $this->assertValidSignature($mesa, $signature);
        $this->expireTimedOutSessions($mesa);

        $plainToken = Str::random(64);

        $session = TableSession::create([
            'mesa_id' => $mesa->id,
            'session_token_hash' => hash('sha256', $plainToken),
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addHours(3),
            'last_seen_at' => now(),
            'client_fingerprint' => $fingerprint,
            'ip_address' => $ipAddress,
        ]);

        return [$session->fresh(), $plainToken];
    }

    public function resolveSession(Mesa $mesa, ?string $plainToken): TableSession
    {
        if (!$plainToken) {
            throw new \RuntimeException('La sesión pública de la mesa es obligatoria');
        }

        $this->expireTimedOutSessions($mesa);

        $session = TableSession::where('mesa_id', $mesa->id)
            ->where('session_token_hash', hash('sha256', $plainToken))
            ->first();

        if (!$session) {
            throw new \RuntimeException('La sesión pública de la mesa ya no es válida');
        }

        if ($session->status === 'expired' || $session->expires_at->isPast()) {
            $session->update([
                'status' => 'expired',
                'ended_at' => now(),
            ]);

            throw new \RuntimeException('La sesión pública de la mesa expiró');
        }

        if ($session->status !== 'active') {
            throw new \RuntimeException('La sesión pública de la mesa ya no es válida');
        }

        $session->update([
            'last_seen_at' => now(),
        ]);

        return $session->fresh();
    }

    public function closeForMesa(Mesa $mesa): void
    {
        TableSession::where('mesa_id', $mesa->id)
            ->where('status', 'active')
            ->update([
                'status' => 'closed',
                'ended_at' => now(),
            ]);
    }

    public function expireTimedOutSessions(?Mesa $mesa = null): void
    {
        $query = TableSession::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now());

        if ($mesa) {
            $query->where('mesa_id', $mesa->id);
        }

        $query->update([
            'status' => 'expired',
            'ended_at' => now(),
        ]);
    }

    public function signatureForMesa(Mesa $mesa): string
    {
        $appKey = config('app.key') ?: env('APP_KEY', 'restaurant-local-key');

        return hash_hmac('sha256', $mesa->uuid, $appKey);
    }

    public function publicUrlForMesa(Mesa $mesa): string
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return "{$frontendUrl}/table/{$mesa->uuid}?sig={$mesa->qr_signature}";
    }

    public function extractSessionToken(Request $request): ?string
    {
        return $request->header('X-Table-Session-Token');
    }

    public function assertQrEnabled(Mesa $mesa): void
    {
        if (!$mesa->is_qr_enabled) {
            throw new \RuntimeException('El QR de esta mesa está deshabilitado');
        }
    }

    public function assertValidSignature(Mesa $mesa, ?string $signature): void
    {
        if (!$signature || !hash_equals($mesa->qr_signature, $signature)) {
            throw new \RuntimeException('La firma del QR no es válida');
        }
    }
}

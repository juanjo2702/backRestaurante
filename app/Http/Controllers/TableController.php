<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use App\Models\PaymentTransaction;
use App\Models\Reserva;
use App\Services\PublicTableSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TableController extends Controller
{
    private static ?bool $supportsCallLifecycleColumns = null;

    public function __construct(
        private readonly PublicTableSessionService $publicTableSessionService,
    ) {
    }

    public function index()
    {
        $mesas = Mesa::with([
            'reservas' => fn ($query) => $query->where('estado', 'pendiente'),
            'pedidos' => fn ($query) => $query->whereIn('estado', ['pendiente', 'preparando', 'listo', 'servido']),
            'meseroAsignado:id,nombre',
            'callAttendedBy:id,nombre',
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ])->get();

        return response()->json(
            $mesas->map(fn (Mesa $mesa) => $this->staffTablePayload($mesa))
        );
    }

    public function show(Mesa $mesa)
    {
        return response()->json(
            $this->staffTablePayload(
                $mesa->load([
                    'reservas' => fn ($query) => $query->whereIn('estado', ['pendiente', 'confirmada'])->orderBy('hora_reserva'),
                    'pedidos' => fn ($query) => $query->whereIn('estado', ['pendiente', 'preparando', 'listo', 'servido']),
                    'meseroAsignado:id,nombre',
                    'callAttendedBy:id,nombre',
                    'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
                ])
            )
        );
    }

    public function update(Request $request, Mesa $mesa)
    {
        $validated = $request->validate([
            'estado' => 'sometimes|in:libre,ocupada,reservada',
            'llamada_tipo' => 'sometimes|nullable|in:attention,bill,order',
            'llamada_estado' => 'sometimes|nullable|in:pending,acknowledged',
            'llamada_timestamp' => 'sometimes|nullable|date',
            'llamada_atendida_por' => 'sometimes|nullable|exists:usuarios,id',
            'llamada_atendida_timestamp' => 'sometimes|nullable|date',
            'mesero_asignado_id' => 'sometimes|nullable|exists:usuarios,id',
            'pago_pendiente_monto' => 'sometimes|nullable|numeric|min:0',
            'pago_pendiente_cliente_pago' => 'sometimes|boolean',
            'pago_pendiente_metodo' => 'sometimes|nullable|in:qr,cash,card',
            'pago_pendiente_fecha' => 'sometimes|nullable|date',
            'ocupada_desde' => 'sometimes|nullable|date',
            'is_qr_enabled' => 'sometimes|boolean',
        ]);

        if (($validated['estado'] ?? null) === 'ocupada' && !$mesa->ocupada_desde) {
            $validated['ocupada_desde'] = now();
        }

        if (($validated['estado'] ?? null) === 'libre') {
            $validated['ocupada_desde'] = null;
            $validated['mesero_asignado_id'] = null;
            $validated['llamada_tipo'] = null;
            $validated['llamada_estado'] = null;
            $validated['llamada_timestamp'] = null;
            $validated['llamada_atendida_por'] = null;
            $validated['llamada_atendida_timestamp'] = null;
            $validated['pago_pendiente_monto'] = null;
            $validated['pago_pendiente_cliente_pago'] = false;
            $validated['pago_pendiente_metodo'] = null;
            $validated['pago_pendiente_fecha'] = null;

            $this->publicTableSessionService->closeForMesa($mesa);
        }

        if (array_key_exists('llamada_tipo', $validated) && $validated['llamada_tipo'] && !isset($validated['llamada_timestamp'])) {
            $validated['llamada_timestamp'] = now();
        }

        if (array_key_exists('llamada_tipo', $validated) && $validated['llamada_tipo'] && !isset($validated['llamada_estado'])) {
            $validated['llamada_estado'] = 'pending';
        }

        if (array_key_exists('llamada_tipo', $validated) && $validated['llamada_tipo'] === null) {
            $validated['llamada_estado'] = null;
            $validated['llamada_timestamp'] = null;
            $validated['llamada_atendida_por'] = null;
            $validated['llamada_atendida_timestamp'] = null;
        }

        $mesa->update($this->sanitizeMesaUpdatePayload($validated));

        EventStreamController::pushEvent(
            'table.updated',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
                'status' => $mesa->estado,
                'call_type' => $mesa->llamada_tipo,
                'call_status' => $mesa->llamada_estado,
                'call_attended_by' => $mesa->llamada_atendida_por,
                'pending_payment_amount' => $mesa->pago_pendiente_monto,
            ],
            ['global', 'role_waiter', 'role_cashier', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh([
            'meseroAsignado:id,nombre',
            'callAttendedBy:id,nombre',
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ])));
    }

    public function assignWaiter(Request $request, Mesa $mesa)
    {
        $validated = $request->validate([
            'mesero_asignado_id' => 'required|exists:usuarios,id',
        ]);

        if ($mesa->mesero_asignado_id && $mesa->mesero_asignado_id !== (int) $validated['mesero_asignado_id']) {
            return response()->json(['message' => 'La mesa ya está asignada a otro mesero'], 409);
        }

        $mesa->update([
            'mesero_asignado_id' => $validated['mesero_asignado_id'],
            'estado' => $mesa->estado === 'libre' ? 'ocupada' : $mesa->estado,
            'ocupada_desde' => $mesa->ocupada_desde ?: now(),
        ]);

        EventStreamController::pushEvent(
            'table.assigned',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
                'waiter_id' => $validated['mesero_asignado_id'],
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh([
            'meseroAsignado:id,nombre',
            'callAttendedBy:id,nombre',
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ])));
    }

    public function occupy(Request $request, Mesa $mesa)
    {
        $mesa->update([
            'estado' => 'ocupada',
            'ocupada_desde' => now(),
        ]);

        EventStreamController::pushEvent(
            'table.occupied',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh()));
    }

    public function free(Request $request, Mesa $mesa)
    {
        $mesa->update($this->sanitizeMesaUpdatePayload([
            'estado' => 'libre',
            'ocupada_desde' => null,
            'mesero_asignado_id' => null,
            'llamada_tipo' => null,
            'llamada_estado' => null,
            'llamada_timestamp' => null,
            'llamada_atendida_por' => null,
            'llamada_atendida_timestamp' => null,
            'pago_pendiente_monto' => null,
            'pago_pendiente_cliente_pago' => false,
            'pago_pendiente_metodo' => null,
            'pago_pendiente_fecha' => null,
        ]));

        $this->publicTableSessionService->closeForMesa($mesa);

        EventStreamController::pushEvent(
            'table.freed',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
            ],
            ['global', 'role_waiter', 'role_cashier', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh()));
    }

    public function acknowledgeCall(Request $request, Mesa $mesa)
    {
        if (!$mesa->llamada_tipo) {
            return response()->json(['message' => 'La mesa no tiene una llamada activa'], 409);
        }

        if (
            $mesa->llamada_estado === 'acknowledged'
            && $mesa->llamada_atendida_por
            && $mesa->llamada_atendida_por !== $request->user()->id
        ) {
            return response()->json(['message' => 'La llamada ya esta siendo atendida por otro mesero'], 409);
        }

        $mesa->update($this->sanitizeMesaUpdatePayload([
            'llamada_estado' => 'acknowledged',
            'llamada_atendida_por' => $request->user()->id,
            'llamada_atendida_timestamp' => now(),
            'mesero_asignado_id' => $mesa->mesero_asignado_id ?: $request->user()->id,
            'estado' => $mesa->estado === 'libre' ? 'ocupada' : $mesa->estado,
            'ocupada_desde' => $mesa->ocupada_desde ?: now(),
        ]));

        EventStreamController::pushEvent(
            'table.updated',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
                'status' => $mesa->estado,
                'call_type' => $mesa->llamada_tipo,
                'call_status' => $mesa->llamada_estado,
                'call_attended_by' => $mesa->llamada_atendida_por,
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh([
            'meseroAsignado:id,nombre',
            'callAttendedBy:id,nombre',
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ])));
    }

    public function resolveCall(Request $request, Mesa $mesa)
    {
        if (!$mesa->llamada_tipo) {
            return response()->json(['message' => 'La mesa no tiene una llamada activa'], 409);
        }

        if (
            $mesa->llamada_estado === 'acknowledged'
            && $mesa->llamada_atendida_por
            && $mesa->llamada_atendida_por !== $request->user()->id
        ) {
            return response()->json(['message' => 'Solo el mesero que la tomo puede cerrarla'], 409);
        }

        $mesa->update($this->sanitizeMesaUpdatePayload([
            'llamada_tipo' => null,
            'llamada_estado' => null,
            'llamada_timestamp' => null,
            'llamada_atendida_por' => null,
            'llamada_atendida_timestamp' => null,
        ]));

        EventStreamController::pushEvent(
            'table.updated',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
                'status' => $mesa->estado,
                'call_type' => null,
                'call_status' => null,
                'call_attended_by' => null,
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json($this->staffTablePayload($mesa->fresh([
            'meseroAsignado:id,nombre',
            'callAttendedBy:id,nombre',
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ])));
    }

    public function createPublicSession(Request $request)
    {
        $validated = $request->validate([
            'mesa_uuid' => 'required|uuid',
            'signature' => 'required|string',
            'fingerprint' => 'nullable|string|max:255',
        ]);

        $mesa = $this->findPublicMesa($validated['mesa_uuid']);

        try {
            [$session, $plainToken] = $this->publicTableSessionService->createSession(
                $mesa,
                $validated['signature'],
                $validated['fingerprint'] ?? null,
                $request->ip()
            );
        } catch (\RuntimeException $exception) {
            return $this->publicErrorResponse($exception);
        }

        EventStreamController::pushEvent(
            'table.public_session.started',
            [
                'table_id' => $mesa->id,
                'table_number' => $mesa->numero,
                'session_id' => $session->id,
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json([
            'table_session_token' => $plainToken,
            'table' => $this->publicTablePayload($mesa->fresh([
                'reservas' => fn ($query) => $query->whereIn('estado', ['pendiente', 'confirmada'])->orderBy('hora_reserva'),
                'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
            ]), $session),
            'expires_at' => $session->expires_at,
        ], 201);
    }

    public function showPublic(Request $request, string $uuid)
    {
        $mesa = $this->findPublicMesa($uuid);
        $mesa->load([
            'reservas' => fn ($query) => $query->whereIn('estado', ['pendiente', 'confirmada'])->orderBy('hora_reserva'),
            'paymentTransactions' => fn ($query) => $query->whereIn('status', ['pending', 'client_paid'])->latest(),
        ]);

        $session = null;
        $providedSignature = $request->query('sig', $request->input('signature'));

        try {
            $token = $this->publicTableSessionService->extractSessionToken($request);
            if ($token) {
                try {
                    $session = $this->publicTableSessionService->resolveSession($mesa, $token);
                } catch (\RuntimeException $exception) {
                    if (!$providedSignature) {
                        throw $exception;
                    }

                    $this->publicTableSessionService->assertQrEnabled($mesa);
                    $this->publicTableSessionService->assertValidSignature($mesa, $providedSignature);
                }
            } else {
                $this->publicTableSessionService->assertQrEnabled($mesa);
                $this->publicTableSessionService->assertValidSignature($mesa, $providedSignature);
            }
        } catch (\RuntimeException $exception) {
            return $this->publicErrorResponse($exception);
        }

        return response()->json($this->publicTablePayload($mesa, $session));
    }

    public function availability(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'hora' => 'required|date_format:H:i',
            'personas' => 'required|integer|min:1',
        ]);

        $fechaHora = \Carbon\Carbon::parse($request->fecha . ' ' . $request->hora);
        $rangoInicio = $fechaHora->copy()->subHours(2);
        $rangoFin = $fechaHora->copy()->addHours(2);

        $mesasDisponibles = Mesa::query()
            ->select(['id', 'numero', 'capacidad', 'ubicacion_x', 'ubicacion_y', 'estado'])
            ->where('capacidad', '>=', $request->personas)
            ->where('capacidad', '<=', $request->personas + 2)
            ->whereDoesntHave('reservas', function ($query) use ($rangoInicio, $rangoFin) {
                $query->where('hora_reserva', '>=', $rangoInicio)
                    ->where('hora_reserva', '<=', $rangoFin)
                    ->whereIn('estado', ['pendiente', 'confirmada']);
            })
            ->orderBy('capacidad')
            ->orderBy('numero')
            ->get();

        return response()->json([
            'disponibles' => $mesasDisponibles,
            'total' => $mesasDisponibles->count(),
            'fecha_hora' => $fechaHora->toIso8601String(),
            'personas' => $request->personas,
        ]);
    }

    public function callPublic(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'tipo' => 'required|in:attention,bill,order',
        ]);

        $mesa = $this->findPublicMesa($uuid);

        try {
            $this->publicTableSessionService->resolveSession($mesa, $this->publicTableSessionService->extractSessionToken($request));
        } catch (\RuntimeException $exception) {
            return $this->publicErrorResponse($exception);
        }

        $mesa->update($this->sanitizeMesaUpdatePayload([
            'llamada_tipo' => $validated['tipo'],
            'llamada_estado' => 'pending',
            'llamada_timestamp' => now(),
            'llamada_atendida_por' => null,
            'llamada_atendida_timestamp' => null,
        ]));

        EventStreamController::pushEvent(
            'table.call',
            [
                'table_number' => $mesa->numero,
                'call_type' => $mesa->llamada_tipo,
                'call_status' => $mesa->llamada_estado,
            ],
            ['global', 'role_waiter', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json([
            'message' => 'Llamada registrada',
            'llamada_tipo' => $mesa->llamada_tipo,
            'llamada_estado' => $mesa->llamada_estado,
            'llamada_timestamp' => $mesa->llamada_timestamp,
        ]);
    }

    public function paymentPublic(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'monto' => 'required|numeric|min:0',
            'metodo' => 'sometimes|nullable|in:qr,cash,card',
        ]);

        $mesa = $this->findPublicMesa($uuid);

        try {
            $this->publicTableSessionService->resolveSession($mesa, $this->publicTableSessionService->extractSessionToken($request));
        } catch (\RuntimeException $exception) {
            return $this->publicErrorResponse($exception);
        }

        if (!$mesa->pago_pendiente_monto || abs((float) $mesa->pago_pendiente_monto - (float) $validated['monto']) > 0.01) {
            return response()->json(['message' => 'El monto no coincide con el pago pendiente'], 400);
        }

        $updateData = [
            'pago_pendiente_cliente_pago' => true,
            'pago_pendiente_fecha' => now(),
        ];

        if (!empty($validated['metodo'])) {
            $updateData['pago_pendiente_metodo'] = $validated['metodo'];
        }

        $mesa->update($updateData);

        PaymentTransaction::where('mesa_id', $mesa->id)
            ->whereIn('status', ['pending', 'client_paid'])
            ->latest()
            ->first()
            ?->update([
                'status' => 'client_paid',
                'method' => $validated['metodo'] ?? 'cash',
                'client_paid_at' => now(),
            ]);

        EventStreamController::pushEvent(
            'payment.received',
            [
                'table_number' => $mesa->numero,
                'amount' => $validated['monto'],
                'method' => $validated['metodo'] ?? 'cash',
            ],
            ['global', 'role_cashier', 'role_admin'],
            'table:' . $mesa->id
        );

        return response()->json([
            'message' => 'Pago registrado',
            'pago_pendiente_cliente_pago' => $mesa->pago_pendiente_cliente_pago,
            'pago_pendiente_fecha' => $mesa->pago_pendiente_fecha,
            'pago_pendiente_metodo' => $mesa->pago_pendiente_metodo,
        ]);
    }

    private function findPublicMesa(string $uuid): Mesa
    {
        $mesa = Mesa::where('uuid', $uuid)->first();

        if (!$mesa) {
            abort(404, 'Mesa no encontrada');
        }

        return $mesa;
    }

    private function publicTablePayload(Mesa $mesa, $session = null): array
    {
        $sessionOrders = $session
            ? $session->pedidos()
                ->with([
                    'mesa',
                    'detalles.producto.categoria',
                ])
                ->latest()
                ->get()
            : collect();

        return [
            'id' => $mesa->id,
            'uuid' => $mesa->uuid,
            'numero' => $mesa->numero,
            'estado' => $mesa->estado,
            'capacidad' => $mesa->capacidad,
            'ubicacion_x' => $mesa->ubicacion_x,
            'ubicacion_y' => $mesa->ubicacion_y,
            'reservas_pendientes' => $mesa->reservas,
            'ocupada_desde' => $mesa->ocupada_desde,
            'llamada_tipo' => $mesa->llamada_tipo,
            'llamada_estado' => $mesa->llamada_estado,
            'llamada_timestamp' => $mesa->llamada_timestamp,
            'llamada_atendida_por' => $mesa->llamada_atendida_por,
            'llamada_atendida_timestamp' => $mesa->llamada_atendida_timestamp,
            'pago_pendiente_monto' => $mesa->pago_pendiente_monto,
            'pago_pendiente_cliente_pago' => $mesa->pago_pendiente_cliente_pago,
            'pago_pendiente_metodo' => $mesa->pago_pendiente_metodo,
            'pago_pendiente_fecha' => $mesa->pago_pendiente_fecha,
            'pending_payment_id' => $mesa->paymentTransactions->first()?->id,
            'is_qr_enabled' => $mesa->is_qr_enabled,
            'qr_signature' => $mesa->qr_signature,
            'public_url' => $this->publicTableSessionService->publicUrlForMesa($mesa),
            'session' => $session ? [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at,
                'expires_at' => $session->expires_at,
                'last_seen_at' => $session->last_seen_at,
            ] : null,
            'session_orders' => $sessionOrders,
        ];
    }

    private function staffTablePayload(Mesa $mesa): array
    {
        return array_merge(
            $mesa->toArray(),
            [
                'public_url' => $this->publicTableSessionService->publicUrlForMesa($mesa),
                'is_qr_enabled' => $mesa->is_qr_enabled,
                'uuid' => $mesa->uuid,
                'qr_signature' => $mesa->qr_signature,
            ]
        );
    }

    private function publicErrorResponse(\RuntimeException $exception)
    {
        $message = $exception->getMessage();
        $status = str_contains(mb_strtolower($message), 'firma') ? 403 : 409;

        if (str_contains(mb_strtolower($message), 'sesión')) {
            $status = 401;
        }

        if (str_contains(mb_strtolower($message), 'deshabilitado')) {
            $status = 403;
        }

        return response()->json(['message' => $message], $status);
    }

    private function sanitizeMesaUpdatePayload(array $payload): array
    {
        if ($this->supportsCallLifecycleColumns()) {
            return $payload;
        }

        unset(
            $payload['llamada_estado'],
            $payload['llamada_atendida_por'],
            $payload['llamada_atendida_timestamp'],
        );

        return $payload;
    }

    private function supportsCallLifecycleColumns(): bool
    {
        if (self::$supportsCallLifecycleColumns !== null) {
            return self::$supportsCallLifecycleColumns;
        }

        self::$supportsCallLifecycleColumns = Schema::hasColumns('mesas', [
            'llamada_estado',
            'llamada_atendida_por',
            'llamada_atendida_timestamp',
        ]);

        return self::$supportsCallLifecycleColumns;
    }
}

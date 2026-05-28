<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use App\Models\Reserva;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReservaController extends Controller
{
    public function index(Request $request)
    {
        $query = Reserva::with(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
            ->orderBy('hora_reserva', 'asc');

        if ($request->filled('date')) {
            $date = Carbon::parse($request->input('date'), config('app.timezone'));
            $query->whereBetween('hora_reserva', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('garantia_estado')) {
            $query->where('garantia_estado', $request->input('garantia_estado'));
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->input('operational_status'));
        }

        return response()->json(
            $query->get()->map(fn (Reserva $reserva) => $this->reservationPayload($reserva))
        );
    }

    public function show(Reserva $reservation)
    {
        return response()->json($this->reservationPayload(
            $reservation->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'mesa_id' => 'required|exists:mesas,id',
            'nombre_cliente' => 'required|string|max:255',
            'cantidad_personas' => 'required|integer|min:1',
            'hora_reserva' => 'required|date',
            'telefono' => 'required|string|max:20',
            'estado' => 'nullable|string|in:pendiente,confirmada,cancelada,completada',
            'garantia_monto' => 'nullable|numeric|min:0',
            'garantia_referencia' => 'nullable|string|max:100',
        ]);

        $validated['hora_reserva'] = $this->normalizeReservationDateTime($validated['hora_reserva']);

        $mesa = Mesa::findOrFail($validated['mesa_id']);
        $this->ensureCapacityAndAvailability($mesa, $validated['cantidad_personas'], $validated['hora_reserva']);

        $reserva = Reserva::create([
            ...$validated,
            'codigo_reserva' => $this->generateReservationCode(),
            'origen' => 'staff',
            'operational_status' => 'scheduled',
            'garantia_monto' => $validated['garantia_monto'] ?? 0,
            'garantia_estado' => ($validated['garantia_monto'] ?? 0) > 0 ? 'pending_review' : 'not_required',
            'tracking_token' => Str::random(40),
        ]);

        if ($mesa->estado === 'libre') {
            $mesa->update(['estado' => 'reservada']);
        }

        return response()->json(
            $this->reservationPayload($reserva->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])),
            201
        );
    }

    public function update(Request $request, Reserva $reserva)
    {
        $validated = $request->validate([
            'mesa_id' => 'sometimes|required|exists:mesas,id',
            'nombre_cliente' => 'sometimes|required|string|max:255',
            'cantidad_personas' => 'sometimes|required|integer|min:1',
            'hora_reserva' => 'sometimes|required|date',
            'telefono' => 'sometimes|required|string|max:20',
            'estado' => 'sometimes|required|string|in:pendiente,confirmada,cancelada,completada',
            'garantia_monto' => 'sometimes|required|numeric|min:0',
            'garantia_referencia' => 'sometimes|nullable|string|max:100',
        ]);

        if (isset($validated['hora_reserva'])) {
            $validated['hora_reserva'] = $this->normalizeReservationDateTime($validated['hora_reserva']);
        }

        $mesa = isset($validated['mesa_id']) ? Mesa::findOrFail($validated['mesa_id']) : $reserva->mesa;
        $personas = $validated['cantidad_personas'] ?? $reserva->cantidad_personas;
        $horaReserva = $validated['hora_reserva'] ?? $reserva->hora_reserva;

        $this->ensureCapacityAndAvailability($mesa, $personas, $horaReserva, $reserva->id);

        $reserva->update($validated);
        $this->syncMesaStateForReservation($reserva->fresh('mesa'));

        return response()->json($this->reservationPayload(
            $reserva->fresh()->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
        ));
    }

    public function destroy(Reserva $reserva)
    {
        $mesa = $reserva->mesa;

        if ($reserva->garantia_comprobante_path) {
            Storage::disk($reserva->garantia_comprobante_disk ?: 'public')->delete($reserva->garantia_comprobante_path);
        }

        $reserva->delete();

        if ($mesa) {
            $this->refreshMesaReservationState($mesa);
        }

        return response()->json(['message' => 'Reserva eliminada']);
    }

    public function active()
    {
        $now = Carbon::now(config('app.timezone'));
        $twoHoursFromNow = $now->copy()->addHours(2);

        $reservas = Reserva::with(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
            ->where('hora_reserva', '>=', $now)
            ->where('hora_reserva', '<=', $twoHoursFromNow)
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->orderBy('hora_reserva', 'asc')
            ->get();

        return response()->json($reservas->map(fn (Reserva $reserva) => $this->reservationPayload($reserva)));
    }

    public function agenda(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()), config('app.timezone'));

        $reservas = Reserva::with(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
            ->whereBetween('hora_reserva', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('hora_reserva', 'asc')
            ->get();

        return response()->json([
            'date' => $date->toDateString(),
            'items' => $reservas->map(fn (Reserva $reserva) => $this->reservationPayload($reserva)),
            'summary' => [
                'total' => $reservas->count(),
                'pending_review' => $reservas->where('garantia_estado', 'pending_review')->count(),
                'confirmed' => $reservas->where('estado', 'confirmada')->count(),
                'scheduled' => $reservas->where('operational_status', 'scheduled')->count(),
                'arrived' => $reservas->where('operational_status', 'arrived')->count(),
                'seated' => $reservas->where('operational_status', 'seated')->count(),
                'no_show' => $reservas->where('operational_status', 'no_show')->count(),
            ],
        ]);
    }

    public function reviewQueue(Request $request)
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'), config('app.timezone'))
            : null;

        $query = Reserva::with(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
            ->where('garantia_estado', 'pending_review')
            ->orderBy('hora_reserva', 'asc');

        if ($date) {
            $query->whereBetween('hora_reserva', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        }

        $reservas = $query->get();

        return response()->json([
            'items' => $reservas->map(fn (Reserva $reserva) => $this->reservationPayload($reserva)),
            'total' => $reservas->count(),
        ]);
    }

    public function review(Request $request, Reserva $reserva)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ]);

        $attributes = [
            'garantia_estado' => $validated['action'] === 'approve' ? 'approved' : 'rejected',
            'garantia_revisada_por' => $request->user()?->id,
            'garantia_revisada_at' => now(),
            'garantia_revision_notas' => $validated['notes'] ?? null,
            'estado' => $validated['action'] === 'approve' ? 'confirmada' : 'pendiente',
        ];

        if ($validated['action'] === 'reject') {
            $attributes['operational_status'] = 'scheduled';
        }

        $reserva->update($attributes);

        return response()->json($this->reservationPayload(
            $reserva->fresh()->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
        ));
    }

    public function updateOperationalStatus(Request $request, Reserva $reserva)
    {
        $validated = $request->validate([
            'status' => 'required|in:scheduled,arrived,seated,no_show,cancelled,completed',
        ]);

        $status = $validated['status'];

        $attributes = [
            'operational_status' => $status,
        ];

        if ($status === 'arrived') {
            $attributes['arrived_at'] = now();
            $attributes['estado'] = 'confirmada';
        } elseif ($status === 'seated') {
            $attributes['seated_at'] = now();
            $attributes['estado'] = 'confirmada';
        } elseif ($status === 'no_show') {
            $attributes['no_show_at'] = now();
            $attributes['estado'] = 'cancelada';
            $attributes['cancelled_at'] = now();
        } elseif ($status === 'cancelled') {
            $attributes['cancelled_at'] = now();
            $attributes['estado'] = 'cancelada';
        } elseif ($status === 'completed') {
            $attributes['completed_at'] = now();
            $attributes['estado'] = 'completada';
        }

        $reserva->update($attributes);
        $this->syncMesaStateForReservation($reserva->fresh('mesa'));

        return response()->json($this->reservationPayload(
            $reserva->fresh()->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
        ));
    }

    public function storePublic(Request $request)
    {
        $validated = $request->validate([
            'mesa_id' => 'required|exists:mesas,id',
            'nombre_cliente' => 'required|string|max:255',
            'cantidad_personas' => 'required|integer|min:1',
            'hora_reserva' => 'required|date',
            'telefono' => 'required|string|max:20',
            'garantia_referencia' => 'nullable|string|max:100',
            'comprobante_garantia' => 'required|image|max:5120',
        ]);

        $validated['hora_reserva'] = $this->normalizeReservationDateTime($validated['hora_reserva']);

        $mesa = Mesa::findOrFail($validated['mesa_id']);
        $this->ensureCapacityAndAvailability($mesa, $validated['cantidad_personas'], $validated['hora_reserva']);

        $proofPath = $request->file('comprobante_garantia')->store('reservation-proofs', 'public');

        $reserva = Reserva::create([
            'codigo_reserva' => $this->generateReservationCode(),
            'mesa_id' => $validated['mesa_id'],
            'nombre_cliente' => $validated['nombre_cliente'],
            'cantidad_personas' => $validated['cantidad_personas'],
            'hora_reserva' => $validated['hora_reserva'],
            'telefono' => $validated['telefono'],
            'estado' => 'pendiente',
            'origen' => 'public',
            'operational_status' => 'scheduled',
            'garantia_monto' => $validated['cantidad_personas'] * 50,
            'garantia_estado' => 'pending_review',
            'garantia_referencia' => $validated['garantia_referencia'] ?? null,
            'garantia_comprobante_disk' => 'public',
            'garantia_comprobante_path' => $proofPath,
            'garantia_subida_at' => now(),
            'tracking_token' => Str::random(40),
        ]);

        if ($mesa->estado === 'libre') {
            $mesa->update(['estado' => 'reservada']);
        }

        return response()->json([
            ...$this->reservationPayload($reserva->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])),
            'tracking_token' => $reserva->tracking_token,
        ], 201);
    }

    public function publicHistory(Request $request)
    {
        $validated = $request->validate([
            'tokens' => 'required|array|min:1|max:50',
            'tokens.*' => 'required|string|min:20|max:80',
        ]);

        $reservas = Reserva::with(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
            ->whereIn('tracking_token', array_values(array_unique($validated['tokens'])))
            ->orderBy('hora_reserva', 'desc')
            ->get();

        return response()->json([
            'items' => $reservas->map(fn (Reserva $reserva) => $this->reservationPayload($reserva)),
            'total' => $reservas->count(),
        ]);
    }

    public function replacePublicProof(Request $request, string $trackingToken)
    {
        $validated = $request->validate([
            'garantia_referencia' => 'nullable|string|max:100',
            'comprobante_garantia' => 'required|image|max:5120',
        ]);

        $reserva = Reserva::where('tracking_token', $trackingToken)->firstOrFail();

        if ($reserva->garantia_comprobante_path) {
            Storage::disk($reserva->garantia_comprobante_disk ?: 'public')->delete($reserva->garantia_comprobante_path);
        }

        $proofPath = $request->file('comprobante_garantia')->store('reservation-proofs', 'public');

        $reserva->update([
            'garantia_referencia' => $validated['garantia_referencia'] ?? $reserva->garantia_referencia,
            'garantia_comprobante_disk' => 'public',
            'garantia_comprobante_path' => $proofPath,
            'garantia_subida_at' => now(),
            'garantia_estado' => 'pending_review',
            'garantia_revisada_por' => null,
            'garantia_revisada_at' => null,
            'garantia_revision_notas' => null,
            'estado' => 'pendiente',
        ]);

        return response()->json($this->reservationPayload(
            $reserva->fresh()->load(['mesa', 'garantiaRevisadaPorUsuario:id,nombre'])
        ));
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'hora' => 'required|date_format:H:i',
            'personas' => 'required|integer|min:1',
        ]);

        $fechaHora = Carbon::parse($request->fecha . ' ' . $request->hora);
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

    private function normalizeReservationDateTime(string $value): string
    {
        return Carbon::parse($value, config('app.timezone'))
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function reservationPayload(Reserva $reserva): array
    {
        $reserva->loadMissing(['mesa', 'garantiaRevisadaPorUsuario:id,nombre']);

        return [
            'id' => $reserva->id,
            'codigo_reserva' => $reserva->codigo_reserva,
            'mesa_id' => $reserva->mesa_id,
            'nombre_cliente' => $reserva->nombre_cliente,
            'cantidad_personas' => $reserva->cantidad_personas,
            'hora_reserva' => $reserva->hora_reserva?->toIso8601String(),
            'telefono' => $reserva->telefono,
            'estado' => $reserva->estado,
            'origen' => $reserva->origen,
            'operational_status' => $reserva->operational_status,
            'garantia_monto' => (float) $reserva->garantia_monto,
            'garantia_estado' => $reserva->garantia_estado,
            'garantia_referencia' => $reserva->garantia_referencia,
            'garantia_comprobante_url' => $reserva->garantia_comprobante_url,
            'garantia_subida_at' => $reserva->garantia_subida_at?->toIso8601String(),
            'garantia_revisada_at' => $reserva->garantia_revisada_at?->toIso8601String(),
            'garantia_revision_notas' => $reserva->garantia_revision_notas,
            'garantia_revisada_por' => $reserva->garantiaRevisadaPorUsuario
                ? [
                    'id' => $reserva->garantiaRevisadaPorUsuario->id,
                    'nombre' => $reserva->garantiaRevisadaPorUsuario->nombre,
                ]
                : null,
            'tracking_token' => $reserva->tracking_token,
            'arrived_at' => $reserva->arrived_at?->toIso8601String(),
            'seated_at' => $reserva->seated_at?->toIso8601String(),
            'no_show_at' => $reserva->no_show_at?->toIso8601String(),
            'cancelled_at' => $reserva->cancelled_at?->toIso8601String(),
            'completed_at' => $reserva->completed_at?->toIso8601String(),
            'mesa' => $reserva->mesa
                ? [
                    'id' => $reserva->mesa->id,
                    'numero' => $reserva->mesa->numero,
                    'capacidad' => $reserva->mesa->capacidad,
                    'estado' => $reserva->mesa->estado,
                ]
                : null,
            'created_at' => $reserva->created_at?->toIso8601String(),
            'updated_at' => $reserva->updated_at?->toIso8601String(),
        ];
    }

    private function ensureCapacityAndAvailability(Mesa $mesa, int $cantidadPersonas, string $horaReserva, ?int $ignoreReservationId = null): void
    {
        if ($mesa->capacidad < $cantidadPersonas) {
            throw new HttpResponseException(response()->json([
                'message' => 'La mesa no tiene capacidad para ' . $cantidadPersonas . ' personas',
            ], 400));
        }

        $reservationTime = Carbon::parse($horaReserva, config('app.timezone'));

        $existingReservation = Reserva::query()
            ->when($ignoreReservationId, fn ($query) => $query->where('id', '!=', $ignoreReservationId))
            ->where('mesa_id', $mesa->id)
            ->where('hora_reserva', '>=', $reservationTime->copy()->subHours(2))
            ->where('hora_reserva', '<=', $reservationTime->copy()->addHours(2))
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->first();

        if ($existingReservation) {
            throw new HttpResponseException(response()->json([
                'message' => 'La mesa ya tiene una reserva en ese horario',
            ], 409));
        }
    }

    private function generateReservationCode(): string
    {
        do {
            $code = 'RSV-' . strtoupper(Str::random(6));
        } while (Reserva::where('codigo_reserva', $code)->exists());

        return $code;
    }

    private function syncMesaStateForReservation(Reserva $reserva): void
    {
        if (!$reserva->mesa) {
            return;
        }

        if ($reserva->operational_status === 'seated') {
            $reserva->mesa->update(['estado' => 'ocupada']);
            return;
        }

        if ($reserva->estado === 'cancelada' || $reserva->operational_status === 'no_show' || $reserva->estado === 'completada') {
            $this->refreshMesaReservationState($reserva->mesa);
            return;
        }

        if (in_array($reserva->estado, ['pendiente', 'confirmada'], true) && $reserva->mesa->estado === 'libre') {
            $reserva->mesa->update(['estado' => 'reservada']);
        }
    }

    private function refreshMesaReservationState(Mesa $mesa): void
    {
        $hasUpcomingReservation = Reserva::query()
            ->where('mesa_id', $mesa->id)
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->whereNotIn('operational_status', ['no_show', 'cancelled', 'completed'])
            ->exists();

        if ($hasUpcomingReservation && $mesa->estado !== 'ocupada') {
            $mesa->update(['estado' => 'reservada']);
            return;
        }

        if ($mesa->estado !== 'ocupada') {
            $mesa->update(['estado' => 'libre']);
        }
    }
}

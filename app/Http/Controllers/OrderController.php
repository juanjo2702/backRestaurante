<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Services\InventoryService;
use App\Services\OrderLifecycleService;
use App\Services\PublicTableSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Pedido::with(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']);

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('tipo')) {
            $query->where('tipo_pedido', $request->tipo);
        }

        return response()->json($query->latest()->get());
    }

    public function show(Pedido $pedido)
    {
        return response()->json($pedido->load(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']));
    }

    public function store(Request $request, InventoryService $inventoryService)
    {
        $validated = $this->validateOrderPayload($request);

        try {
            DB::beginTransaction();

            $pedido = Pedido::create([
                'mesa_id' => $validated['mesa_id'] ?? null,
                'usuario_id' => $request->user()->id,
                'table_session_id' => null,
                'order_source' => $validated['order_type'] === 'mesa' ? 'staff' : 'takeaway',
                'tipo_pedido' => $validated['order_type'],
                'nombre_cliente' => $validated['customer_name'] ?? null,
                'telefono_cliente' => $validated['customer_phone'] ?? null,
                'estado' => 'pendiente',
                'total' => 0,
            ]);

            $total = $this->persistOrderItems($pedido, $validated['items']);
            $pedido->update(['total' => $total]);

            if ($validated['order_type'] === 'mesa' && !empty($validated['mesa_id'])) {
                Mesa::where('id', $validated['mesa_id'])->update([
                    'estado' => 'ocupada',
                    'ocupada_desde' => now(),
                ]);
            }

            $inventoryService->validateAvailabilityForOrder($pedido->load('detalles.producto.ingredientes'));
            DB::commit();

            EventStreamController::pushEvent(
                'order.created',
                [
                    'order_id' => $pedido->id,
                    'table_number' => $pedido->mesa?->numero,
                    'order_type' => $pedido->tipo_pedido,
                    'customer_name' => $pedido->nombre_cliente,
                ],
                ['global', 'role_kitchen', 'role_cashier', 'role_admin'],
                'order:' . $pedido->id
            );

            return response()->json($pedido->load(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']), 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Pedido $pedido, OrderLifecycleService $orderLifecycle)
    {
        $validated = $request->validate([
            'estado' => 'sometimes|in:pendiente,preparando,listo,servido,pagado,cancelado',
            'metodo_pago' => 'sometimes|string|in:cash,card,qr,efectivo,tarjeta',
            'reason' => 'nullable|string|max:500',
        ]);

        if (isset($validated['metodo_pago'])) {
            $pedido->update(['metodo_pago' => $validated['metodo_pago']]);
        }

        if (isset($validated['estado'])) {
            try {
                $pedido = $orderLifecycle->transition(
                    pedido: $pedido->loadMissing('detalles.producto.ingredientes', 'mesa'),
                    toStatus: $validated['estado'],
                    actor: $request->user(),
                    reason: $validated['reason'] ?? null
                );
            } catch (\RuntimeException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        return response()->json($pedido->load(['detalles.producto.categoria', 'mesa', 'usuario', 'paymentTransactions']));
    }

    public function storePublic(Request $request, InventoryService $inventoryService)
    {
        $validated = $this->validateOrderPayload($request);
        $mesa = null;

        if ($validated['order_type'] === 'mesa' && !empty($validated['mesa_id'])) {
            $mesa = Mesa::find($validated['mesa_id']);

            if (!$mesa) {
                return response()->json(['message' => 'Mesa no encontrada'], 404);
            }

            if ($mesa->estado === 'reservada') {
                return response()->json(['message' => 'La mesa está reservada'], 400);
            }
        }

        try {
            DB::beginTransaction();

            $pedido = Pedido::create([
                'mesa_id' => $validated['mesa_id'] ?? null,
                'usuario_id' => null,
                'table_session_id' => null,
                'order_source' => $validated['order_type'] === 'mesa' ? 'public_table' : 'takeaway',
                'tipo_pedido' => $validated['order_type'],
                'nombre_cliente' => $validated['customer_name'] ?? null,
                'telefono_cliente' => $validated['customer_phone'] ?? null,
                'estado' => 'pendiente',
                'total' => 0,
            ]);

            $total = $this->persistOrderItems($pedido, $validated['items']);
            $pedido->update(['total' => $total]);

            if ($mesa && $mesa->estado === 'libre') {
                $mesa->update([
                    'estado' => 'ocupada',
                    'ocupada_desde' => now(),
                ]);
            }

            $inventoryService->validateAvailabilityForOrder($pedido->load('detalles.producto.ingredientes'));
            DB::commit();

            EventStreamController::pushEvent(
                'order.created',
                [
                    'order_id' => $pedido->id,
                    'table_number' => $pedido->mesa?->numero,
                    'order_type' => $pedido->tipo_pedido,
                    'customer_name' => $pedido->nombre_cliente,
                ],
                ['global', 'role_kitchen', 'role_cashier', 'role_admin'],
                'order:' . $pedido->id
            );

            return response()->json([
                'id' => $pedido->id,
                'order_type' => $pedido->tipo_pedido,
                'status' => $pedido->estado,
                'total' => $pedido->total,
                'created_at' => $pedido->created_at,
                'items_count' => count($validated['items']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function storePublicForTable(
        Request $request,
        string $uuid,
        InventoryService $inventoryService,
        PublicTableSessionService $publicTableSessionService
    ) {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:productos,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
        ]);

        $mesa = Mesa::where('uuid', $uuid)->first();

        if (!$mesa) {
            return response()->json(['message' => 'Mesa no encontrada'], 404);
        }

        try {
            $session = $publicTableSessionService->resolveSession(
                $mesa,
                $publicTableSessionService->extractSessionToken($request)
            );
        } catch (\RuntimeException $exception) {
            $status = str_contains(mb_strtolower($exception->getMessage()), 'sesión') ? 401 : 409;
            return response()->json(['message' => $exception->getMessage()], $status);
        }

        if ($mesa->estado === 'reservada') {
            return response()->json(['message' => 'La mesa está reservada'], 400);
        }

        try {
            DB::beginTransaction();

            $pedido = Pedido::create([
                'mesa_id' => $mesa->id,
                'usuario_id' => null,
                'table_session_id' => $session->id,
                'order_source' => 'public_table',
                'tipo_pedido' => 'mesa',
                'nombre_cliente' => $validated['customer_name'] ?? null,
                'telefono_cliente' => $validated['customer_phone'] ?? null,
                'estado' => 'pendiente',
                'total' => 0,
            ]);

            $total = $this->persistOrderItems($pedido, $validated['items']);
            $pedido->update(['total' => $total]);

            if ($mesa->estado === 'libre') {
                $mesa->update([
                    'estado' => 'ocupada',
                    'ocupada_desde' => now(),
                ]);
            }

            $inventoryService->validateAvailabilityForOrder($pedido->load('detalles.producto.ingredientes'));
            DB::commit();

            EventStreamController::pushEvent(
                'order.created',
                [
                    'order_id' => $pedido->id,
                    'table_number' => $pedido->mesa?->numero,
                    'order_type' => $pedido->tipo_pedido,
                    'customer_name' => $pedido->nombre_cliente,
                ],
                ['global', 'role_kitchen', 'role_cashier', 'role_admin'],
                'order:' . $pedido->id
            );

            return response()->json([
                'id' => $pedido->id,
                'order_type' => $pedido->tipo_pedido,
                'status' => $pedido->estado,
                'total' => $pedido->total,
                'created_at' => $pedido->created_at,
                'items_count' => count($validated['items']),
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    private function validateOrderPayload(Request $request): array
    {
        return $request->validate([
            'mesa_id' => 'nullable|exists:mesas,id',
            'order_type' => 'required|in:mesa,llevar',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:productos,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'customer_name' => 'nullable|required_if:order_type,llevar|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
        ]);
    }

    private function persistOrderItems(Pedido $pedido, array $items): float
    {
        $total = 0;

        foreach ($items as $item) {
            $producto = Producto::with('ingredientes')->find($item['product_id']);

            if (!$producto || !$producto->disponible) {
                throw new \RuntimeException("Producto no disponible: {$item['product_id']}");
            }

            $subtotal = (float) $producto->precio * $item['quantity'];

            DetallePedido::create([
                'pedido_id' => $pedido->id,
                'producto_id' => $producto->id,
                'cantidad' => $item['quantity'],
                'notas' => $item['notes'] ?? null,
                'precio_unitario' => $producto->precio,
                'subtotal' => $subtotal,
            ]);

            $total += $subtotal;
        }

        return $total;
    }
}

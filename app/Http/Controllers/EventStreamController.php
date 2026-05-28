<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\RealtimeEventService;

class EventStreamController extends Controller
{
    public function __construct(
        private readonly RealtimeEventService $realtime,
    ) {
    }

    public static function pushEvent($type, $data = [], $channels = ['global'], ?string $aggregateId = null)
    {
        app(RealtimeEventService::class)->publish($type, $data, $channels, $aggregateId);
    }

    public function stream(Request $request)
    {
        $user = $request->user();
        $lastId = (int) ($request->header('Last-Event-ID') ?? $request->query('last_event_id', 0));
        $channels = $this->channelsForUser($user);

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($channels, $lastId) {
            $currentLastId = $lastId;

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $heartbeat = [
                'id' => $currentLastId,
                'type' => 'heartbeat',
                'payload' => ['time' => now()->toIso8601String()],
                'occurred_at' => now()->toIso8601String(),
                'aggregate_id' => 'heartbeat',
            ];
            $this->sendEvent($heartbeat);

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $events = $this->realtime->eventsForChannels($channels, $currentLastId);

                foreach ($events as $event) {
                    $currentLastId = max($currentLastId, (int) $event['id']);
                    $this->sendEvent($event);
                }

                $heartbeat = [
                    'id' => $currentLastId,
                    'type' => 'heartbeat',
                    'payload' => ['time' => now()->toIso8601String()],
                    'occurred_at' => now()->toIso8601String(),
                    'aggregate_id' => 'heartbeat',
                ];
                $this->sendEvent($heartbeat);

                sleep(5);
            }
        });

        return $response;
    }

    private function sendEvent(array $event): void
    {
        echo "id: {$event['id']}\n";
        echo "event: {$event['type']}\n";
        echo 'data: ' . json_encode($event) . "\n\n";
        flush();
    }

    private function channelsForUser($user): array
    {
        $channels = ['global'];

        if (!$user) {
            return $channels;
        }

        $channels[] = 'user_' . $user->id;
        $channels[] = 'role_' . $user->rol?->nombre;

        return array_values(array_unique(array_filter($channels)));
    }

    public function notifyNewOrder($order)
    {
        self::pushEvent('order.created', [
            'order_id' => $order->id,
            'table_number' => $order->mesa?->numero,
            'order_type' => $order->tipo_pedido,
            'customer_name' => $order->nombre_cliente,
        ], ['global', 'role_kitchen', 'role_cashier', 'role_admin'], 'order:' . $order->id);
    }

    public function notifyOrderStatusChange($order)
    {
        self::pushEvent('order.status.updated', [
            'order_id' => $order->id,
            'status' => $order->estado,
            'table_number' => $order->mesa?->numero,
        ], ['global', 'role_kitchen', 'role_cashier', 'role_admin', 'role_waiter', 'user_' . $order->usuario_id], 'order:' . $order->id);
    }

    public function notifyTableCall($table)
    {
        self::pushEvent('table.call', [
            'table_number' => $table->numero,
            'call_type' => $table->llamada_tipo,
        ], ['global', 'role_waiter', 'role_admin'], 'table:' . $table->id);
    }

    public function notifyPayment($table, $amount)
    {
        self::pushEvent('payment.received', [
            'table_number' => $table->numero,
            'amount' => $amount,
        ], ['global', 'role_cashier', 'role_admin'], 'table:' . $table->id);
    }
}

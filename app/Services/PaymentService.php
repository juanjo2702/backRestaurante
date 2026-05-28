<?php

namespace App\Services;

use App\Models\BillAccount;
use App\Models\Mesa;
use App\Models\MesaBill;
use App\Models\PaymentTransaction;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly RealtimeEventService $realtime,
        private readonly PublicTableSessionService $publicTableSessionService,
        private readonly SplitBillService $splitBillService,
    ) {
    }

    public function createIntentForTable(Mesa $mesa, string $method, ?User $actor = null): PaymentTransaction
    {
        $bill = $this->splitBillService->getActiveBill($mesa);

        if ($bill) {
            $bill = $this->splitBillService->refreshBill($bill);

            if ($this->money($bill->outstanding_amount) <= 0) {
                throw new \RuntimeException('La mesa no tiene saldo pendiente de cobro');
            }

            return DB::transaction(function () use ($mesa, $method, $actor, $bill) {
                PaymentTransaction::where('mesa_bill_id', $bill->id)
                    ->whereIn('status', ['pending', 'client_paid'])
                    ->update(['status' => 'cancelled']);

                $payment = PaymentTransaction::create([
                    'mesa_id' => $mesa->id,
                    'mesa_bill_id' => $bill->id,
                    'initiated_by' => $actor?->id,
                    'amount' => $bill->outstanding_amount,
                    'method' => $method,
                    'status' => 'pending',
                    'reference' => 'BILL-' . $mesa->numero . '-' . now()->format('YmdHis'),
                    'notes' => 'Cobro total de cuenta dividida',
                ]);

                $this->updateMesaPendingProjection($mesa, (float) $bill->outstanding_amount, $method, false);

                return $payment->fresh();
            });
        }

        $orders = $this->unpaidTableOrders($mesa);
        $amount = $orders->sum('total');

        if ($amount <= 0) {
            throw new \RuntimeException('La mesa no tiene pedidos pendientes de cobro');
        }

        return DB::transaction(function () use ($mesa, $method, $actor, $amount, $orders) {
            PaymentTransaction::where('mesa_id', $mesa->id)
                ->whereIn('status', ['pending', 'client_paid'])
                ->update(['status' => 'cancelled']);

            $payment = PaymentTransaction::create([
                'mesa_id' => $mesa->id,
                'pedido_id' => $orders->count() === 1 ? $orders->first()->id : null,
                'initiated_by' => $actor?->id,
                'amount' => $amount,
                'method' => $method,
                'status' => 'pending',
                'reference' => 'MESA-' . $mesa->numero . '-' . now()->format('YmdHis'),
            ]);

            $this->updateMesaPendingProjection($mesa, $amount, $method, false);

            return $payment->fresh();
        });
    }

    public function createIntentForBillAccount(int $billAccountId, string $method, ?User $actor = null): PaymentTransaction
    {
        $account = $this->splitBillService->getBillAccountForPayment($billAccountId);
        $bill = $account->mesaBill;
        $mesa = $bill->mesa;

        return DB::transaction(function () use ($account, $bill, $mesa, $method, $actor) {
            PaymentTransaction::where('mesa_bill_id', $bill->id)
                ->where(function ($query) use ($account) {
                    $query->where('bill_account_id', $account->id)
                        ->orWhereNull('bill_account_id');
                })
                ->whereIn('status', ['pending', 'client_paid'])
                ->update(['status' => 'cancelled']);

            $relatedOrderIds = $account->allocations()
                ->with('detallePedido')
                ->get()
                ->pluck('detallePedido.pedido_id')
                ->filter()
                ->unique()
                ->values();

            $payment = PaymentTransaction::create([
                'mesa_id' => $mesa?->id,
                'mesa_bill_id' => $bill->id,
                'bill_account_id' => $account->id,
                'pedido_id' => $relatedOrderIds->count() === 1 ? $relatedOrderIds->first() : null,
                'initiated_by' => $actor?->id,
                'amount' => $account->outstanding_amount,
                'method' => $method,
                'status' => 'pending',
                'reference' => 'SUB-' . $bill->id . '-' . $account->id . '-' . now()->format('YmdHis'),
                'notes' => 'Cobro de subcuenta',
            ]);

            return $payment->fresh();
        });
    }

    public function markClientPaid(PaymentTransaction $payment, ?User $actor = null): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor) {
            $payment->update([
                'status' => 'client_paid',
                'client_paid_at' => now(),
            ]);

            if ($payment->mesa && !$payment->bill_account_id) {
                $payment->mesa->update([
                    'pago_pendiente_cliente_pago' => true,
                    'pago_pendiente_fecha' => now(),
                ]);
            }

            $this->realtime->publish(
                type: 'payment.client_paid',
                payload: [
                    'payment_id' => $payment->id,
                    'table_number' => $payment->mesa?->numero,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'bill_account_id' => $payment->bill_account_id,
                ],
                channels: ['global', 'role_admin', 'role_cashier'],
                aggregateId: 'payment:' . $payment->id
            );

            return $payment->fresh();
        });
    }

    public function confirm(PaymentTransaction $payment, ?User $actor = null): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor) {
            if (!in_array($payment->status, ['pending', 'client_paid'], true)) {
                throw new \RuntimeException('El pago ya no puede confirmarse');
            }

            $payment->update([
                'status' => 'confirmed',
                'confirmed_by' => $actor?->id,
                'confirmed_at' => now(),
            ]);

            if ($payment->mesa_bill_id) {
                $this->splitBillService->handleConfirmedPayment($payment->fresh(), $actor);

                if ($payment->mesa) {
                    $this->clearMesaPendingProjection($payment->mesa);
                }

                $this->publishConfirmedPayment($payment->fresh());

                return $payment->fresh();
            }

            if ($payment->pedido_id) {
                $pedido = Pedido::findOrFail($payment->pedido_id);
                $pedido->update(['metodo_pago' => $payment->method]);
                $this->orderLifecycle->transition($pedido, 'pagado', $actor, 'Pago confirmado');
            }

            if ($payment->mesa_id) {
                $mesa = Mesa::findOrFail($payment->mesa_id);
                $orders = $this->unpaidTableOrders($mesa);

                foreach ($orders as $pedido) {
                    $pedido->update(['metodo_pago' => $payment->method]);
                    $this->orderLifecycle->transition($pedido, 'pagado', $actor, 'Pago confirmado por mesa');
                }

                $mesa->update([
                    'estado' => 'libre',
                    'mesero_asignado_id' => null,
                    'ocupada_desde' => null,
                    'llamada_tipo' => null,
                    'llamada_timestamp' => null,
                    'pago_pendiente_monto' => null,
                    'pago_pendiente_cliente_pago' => false,
                    'pago_pendiente_metodo' => null,
                    'pago_pendiente_fecha' => null,
                ]);

                $this->publicTableSessionService->closeForMesa($mesa);
            }

            $this->publishConfirmedPayment($payment->fresh());

            return $payment->fresh();
        });
    }

    private function unpaidTableOrders(Mesa $mesa): Collection
    {
        return Pedido::where('mesa_id', $mesa->id)
            ->whereIn('estado', ['listo', 'servido'])
            ->get();
    }

    private function updateMesaPendingProjection(Mesa $mesa, float $amount, string $method, bool $clientPaid): void
    {
        $mesa->update([
            'pago_pendiente_monto' => $amount,
            'pago_pendiente_metodo' => $method,
            'pago_pendiente_cliente_pago' => $clientPaid,
            'pago_pendiente_fecha' => $clientPaid ? now() : null,
        ]);
    }

    private function clearMesaPendingProjection(Mesa $mesa): void
    {
        $mesa->update([
            'pago_pendiente_monto' => null,
            'pago_pendiente_cliente_pago' => false,
            'pago_pendiente_metodo' => null,
            'pago_pendiente_fecha' => null,
        ]);
    }

    private function publishConfirmedPayment(PaymentTransaction $payment): void
    {
        $this->realtime->publish(
            type: 'payment.confirmed',
            payload: [
                'payment_id' => $payment->id,
                'table_number' => $payment->mesa?->numero,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'bill_account_id' => $payment->bill_account_id,
                'mesa_bill_id' => $payment->mesa_bill_id,
            ],
            channels: ['global', 'role_admin', 'role_cashier', 'role_waiter'],
            aggregateId: 'payment:' . $payment->id
        );
    }

    private function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }
}

<?php

namespace App\Services;

use App\Models\BillAccount;
use App\Models\BillAccountAllocation;
use App\Models\DetallePedido;
use App\Models\Mesa;
use App\Models\MesaBill;
use App\Models\PaymentTransaction;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SplitBillService
{
    public function __construct(
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly RealtimeEventService $realtime,
        private readonly PublicTableSessionService $publicTableSessionService,
    ) {
    }

    public function getActiveBill(Mesa $mesa): ?MesaBill
    {
        return MesaBill::where('mesa_id', $mesa->id)
            ->whereIn('status', ['open', 'settling'])
            ->latest('id')
            ->first();
    }

    public function getPayloadForMesa(Mesa $mesa): ?array
    {
        $bill = $this->getActiveBill($mesa);

        if (!$bill) {
            return null;
        }

        $bill = $this->recalculateBill($bill->id);

        return $this->payload($bill);
    }

    public function refreshBill(MesaBill $bill): MesaBill
    {
        return $this->recalculateBill($bill->id);
    }

    public function initializeForMesa(Mesa $mesa, string $strategy = 'by_session', bool $reset = false): array
    {
        $bill = DB::transaction(function () use ($mesa, $strategy, $reset) {
            $orders = $this->payableOrdersForMesa($mesa);

            if ($orders->isEmpty()) {
                throw new \RuntimeException('La mesa no tiene pedidos listos o servidos para dividir');
            }

            $bill = $this->getOrCreateBill($mesa);

            if ($reset) {
                $this->resetBillStructure($bill);
            }

            if ($strategy === 'equal_split') {
                $this->initializeEqualSplit($bill, $orders);
            } else {
                $this->initializeBySession($bill, $orders);
            }

            return $this->recalculateBill($bill->id);
        });

        $this->publishUpdate($mesa->id, $bill->id, 'split_bill.initialized');

        return $this->payload($bill);
    }

    public function createManualAccount(Mesa $mesa, string $displayName): array
    {
        $bill = DB::transaction(function () use ($mesa, $displayName) {
            $bill = $this->getOrCreateBill($mesa);

            BillAccount::create([
                'mesa_bill_id' => $bill->id,
                'display_name' => $displayName,
                'owner_type' => 'manual',
                'status' => 'open',
                'sort_order' => $this->nextSortOrder($bill),
            ]);

            return $this->recalculateBill($bill->id);
        });

        $this->publishUpdate($mesa->id, $bill->id, 'split_bill.account_created');

        return $this->payload($bill);
    }

    public function updateAccount(Mesa $mesa, BillAccount $account, array $payload): array
    {
        $bill = DB::transaction(function () use ($mesa, $account, $payload) {
            $this->guardAccountBelongsToMesa($mesa, $account);

            if (($payload['action'] ?? null) === 'merge') {
                $target = BillAccount::findOrFail($payload['target_account_id']);
                $this->guardAccountBelongsToMesa($mesa, $target);
                $this->mergeAccounts($account, $target);
            } else {
                $account->update(array_filter([
                    'display_name' => $payload['display_name'] ?? null,
                    'sort_order' => $payload['sort_order'] ?? null,
                ], static fn ($value) => $value !== null));
            }

            return $this->recalculateBill($account->mesa_bill_id);
        });

        $this->publishUpdate($mesa->id, $bill->id, 'split_bill.account_updated');

        return $this->payload($bill);
    }

    public function mutateAllocations(Mesa $mesa, array $payload): array
    {
        $bill = DB::transaction(function () use ($mesa, $payload) {
            $allocation = BillAccountAllocation::with('billAccount')->findOrFail($payload['allocation_id']);
            $this->guardAccountBelongsToMesa($mesa, $allocation->billAccount);

            $target = BillAccount::findOrFail($payload['target_account_id']);
            $this->guardAccountBelongsToMesa($mesa, $target);

            if (($payload['action'] ?? 'move') === 'split') {
                $this->splitAllocation($allocation, $target, $payload);
            } else {
                $this->moveAllocation($allocation, $target);
            }

            return $this->recalculateBill($allocation->billAccount->mesa_bill_id);
        });

        $this->publishUpdate($mesa->id, $bill->id, 'split_bill.allocations_updated');

        return $this->payload($bill);
    }

    public function getBillAccountForPayment(int $billAccountId): BillAccount
    {
        $account = BillAccount::with('mesaBill.mesa')->findOrFail($billAccountId);
        $account = $this->recalculateBill($account->mesa_bill_id)->accounts->firstWhere('id', $account->id)
            ?? $account->fresh(['mesaBill.mesa']);

        if ($this->money($account->outstanding_amount) <= 0) {
            throw new \RuntimeException('La subcuenta ya no tiene saldo pendiente');
        }

        if ($account->status === 'merged') {
            throw new \RuntimeException('La subcuenta ya no esta disponible');
        }

        return $account;
    }

    public function handleConfirmedPayment(PaymentTransaction $payment, ?User $actor = null): void
    {
        if (!$payment->mesa_bill_id) {
            return;
        }

        DB::transaction(function () use ($payment, $actor) {
            $payment = $payment->fresh(['mesaBill.mesa', 'billAccount']);

            if (!$payment->bill_account_id) {
                $this->materializeWholeBillPayment($payment, $actor);
            }

            $bill = $this->recalculateBill($payment->mesa_bill_id);
            $this->syncOrdersForBill($bill, $actor);
            $bill = $this->recalculateBill($bill->id);

            if ($bill->status === 'settled') {
                $mesa = $bill->mesa()->first();

                if ($mesa) {
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
            }
        });

        $this->publishUpdate($payment->mesa_id, $payment->mesa_bill_id, 'split_bill.payment_confirmed');
    }

    private function getOrCreateBill(Mesa $mesa): MesaBill
    {
        return $this->getActiveBill($mesa) ?? MesaBill::create([
            'mesa_id' => $mesa->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function resetBillStructure(MesaBill $bill): void
    {
        $confirmedPayments = PaymentTransaction::where('mesa_bill_id', $bill->id)
            ->where('status', 'confirmed')
            ->exists();

        if ($confirmedPayments) {
            throw new \RuntimeException('La cuenta ya tiene pagos confirmados y no puede reiniciarse');
        }

        PaymentTransaction::where('mesa_bill_id', $bill->id)
            ->whereIn('status', ['pending', 'client_paid'])
            ->update(['status' => 'cancelled']);

        $bill->accounts()->delete();
    }

    private function initializeBySession(MesaBill $bill, Collection $orders): void
    {
        $accounts = [];

        foreach ($this->defaultAccountDescriptors($orders) as $descriptor) {
            $key = $descriptor['key'];
            $accounts[$key] = $this->ensureAccount(
                $bill,
                $descriptor['display_name'],
                $descriptor['owner_type'],
                $descriptor['table_session_id']
            );
        }

        foreach ($orders as $order) {
            foreach ($order->detalles as $detail) {
                $remaining = $this->remainingAmountToAllocate($bill, $detail);

                if ($remaining <= 0) {
                    continue;
                }

                $key = $order->table_session_id ? 'session:' . $order->table_session_id : 'common';
                $targetAccount = $accounts[$key] ?? $accounts['common'];

                BillAccountAllocation::create([
                    'bill_account_id' => $targetAccount->id,
                    'detalle_pedido_id' => $detail->id,
                    'source_table_session_id' => $order->table_session_id,
                    'allocation_type' => 'full',
                    'allocated_amount' => $remaining,
                    'allocated_ratio' => $this->ratioForAmount($detail, $remaining),
                ]);
            }
        }
    }

    private function initializeEqualSplit(MesaBill $bill, Collection $orders): void
    {
        $descriptors = $this->defaultAccountDescriptors($orders);

        if (empty($descriptors)) {
            $descriptors = [[
                'key' => 'common',
                'display_name' => 'Cuenta comun',
                'owner_type' => 'manual',
                'table_session_id' => null,
            ]];
        }

        $accounts = collect($descriptors)->map(function (array $descriptor) use ($bill) {
            return $this->ensureAccount(
                $bill,
                $descriptor['display_name'],
                $descriptor['owner_type'],
                $descriptor['table_session_id']
            );
        })->values();

        $participants = max(1, $accounts->count());

        foreach ($orders as $order) {
            foreach ($order->detalles as $detail) {
                $remaining = $this->remainingAmountToAllocate($bill, $detail);

                if ($remaining <= 0) {
                    continue;
                }

                $baseShare = floor(($remaining / $participants) * 100) / 100;
                $distributed = 0.0;

                foreach ($accounts as $index => $account) {
                    $amount = $index === $participants - 1
                        ? $this->money($remaining - $distributed)
                        : $this->money($baseShare);

                    if ($amount <= 0) {
                        continue;
                    }

                    $distributed += $amount;

                    BillAccountAllocation::create([
                        'bill_account_id' => $account->id,
                        'detalle_pedido_id' => $detail->id,
                        'source_table_session_id' => $order->table_session_id,
                        'allocation_type' => 'split_equal',
                        'allocated_amount' => $amount,
                        'allocated_ratio' => $this->ratioForAmount($detail, $amount),
                    ]);
                }
            }
        }
    }

    private function ensureAccount(
        MesaBill $bill,
        string $displayName,
        string $ownerType,
        ?int $tableSessionId = null
    ): BillAccount {
        $query = BillAccount::where('mesa_bill_id', $bill->id)
            ->where('status', '!=', 'merged');

        if ($tableSessionId) {
            $query->where('table_session_id', $tableSessionId);
        } else {
            $query->whereNull('table_session_id')->where('display_name', $displayName);
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        return BillAccount::create([
            'mesa_bill_id' => $bill->id,
            'table_session_id' => $tableSessionId,
            'display_name' => $displayName,
            'owner_type' => $ownerType,
            'status' => 'open',
            'sort_order' => $this->nextSortOrder($bill),
        ]);
    }

    private function defaultAccountDescriptors(Collection $orders): array
    {
        $descriptors = [];
        $sessionIndex = 1;

        foreach ($orders->sortBy(fn (Pedido $order) => $order->created_at?->timestamp ?? 0) as $order) {
            if ($order->table_session_id) {
                $key = 'session:' . $order->table_session_id;
                if (!isset($descriptors[$key])) {
                    $descriptors[$key] = [
                        'key' => $key,
                        'display_name' => $order->nombre_cliente ?: 'Dispositivo ' . $sessionIndex,
                        'owner_type' => 'session',
                        'table_session_id' => $order->table_session_id,
                    ];
                    $sessionIndex++;
                }
                continue;
            }

            if (!isset($descriptors['common'])) {
                $descriptors['common'] = [
                    'key' => 'common',
                    'display_name' => 'Cuenta comun',
                    'owner_type' => 'manual',
                    'table_session_id' => null,
                ];
            }
        }

        return array_values($descriptors);
    }

    private function remainingAmountToAllocate(MesaBill $bill, DetallePedido $detail): float
    {
        $allocated = BillAccountAllocation::query()
            ->where('detalle_pedido_id', $detail->id)
            ->whereHas('billAccount', fn ($query) => $query->where('mesa_bill_id', $bill->id))
            ->sum('allocated_amount');

        return max(0, $this->money($detail->subtotal - $allocated));
    }

    private function moveAllocation(BillAccountAllocation $allocation, BillAccount $target): void
    {
        if ($allocation->bill_account_id === $target->id) {
            return;
        }

        $this->guardEditableAccount($allocation->billAccount);
        $this->guardEditableAccount($target);

        $allocation->update([
            'bill_account_id' => $target->id,
            'allocation_type' => 'full',
        ]);
    }

    private function splitAllocation(BillAccountAllocation $allocation, BillAccount $target, array $payload): void
    {
        $this->guardEditableAccount($allocation->billAccount);
        $this->guardEditableAccount($target);

        $currentAmount = $this->money($allocation->allocated_amount);
        $targetAmount = isset($payload['amount'])
            ? $this->money($payload['amount'])
            : $this->money($currentAmount * (float) ($payload['ratio'] ?? 0));

        if ($targetAmount <= 0 || $targetAmount >= $currentAmount) {
            throw new \RuntimeException('El monto a dividir no es valido');
        }

        $remainingAmount = $this->money($currentAmount - $targetAmount);

        $allocation->update([
            'allocated_amount' => $remainingAmount,
            'allocated_ratio' => $this->ratioForAmount($allocation->detallePedido, $remainingAmount),
            'allocation_type' => 'split_custom',
        ]);

        BillAccountAllocation::create([
            'bill_account_id' => $target->id,
            'detalle_pedido_id' => $allocation->detalle_pedido_id,
            'source_table_session_id' => $allocation->source_table_session_id,
            'allocation_type' => 'split_custom',
            'allocated_amount' => $targetAmount,
            'allocated_ratio' => $this->ratioForAmount($allocation->detallePedido, $targetAmount),
            'notes' => $allocation->notes,
        ]);
    }

    private function mergeAccounts(BillAccount $source, BillAccount $target): void
    {
        if ($source->id === $target->id) {
            return;
        }

        $this->guardEditableAccount($source);
        $this->guardEditableAccount($target);

        BillAccountAllocation::where('bill_account_id', $source->id)
            ->update(['bill_account_id' => $target->id]);

        $source->update([
            'status' => 'merged',
            'subtotal_amount' => 0,
            'paid_amount' => 0,
            'outstanding_amount' => 0,
        ]);
    }

    private function guardEditableAccount(BillAccount $account): void
    {
        $account = $account->fresh();

        if (in_array($account->status, ['paid', 'merged'], true) || $this->money($account->paid_amount) > 0) {
            throw new \RuntimeException('La subcuenta ya no puede editarse');
        }
    }

    private function guardAccountBelongsToMesa(Mesa $mesa, BillAccount $account): void
    {
        $account->loadMissing('mesaBill');

        if ($account->mesaBill?->mesa_id !== $mesa->id) {
            throw new \RuntimeException('La subcuenta no pertenece a la mesa seleccionada');
        }
    }

    private function recalculateBill(int $billId): MesaBill
    {
        $bill = MesaBill::with([
            'mesa',
            'accounts.allocations.detallePedido.pedido',
            'accounts.allocations.detallePedido.producto',
            'accounts.paymentTransactions' => fn ($query) => $query->where('status', 'confirmed'),
        ])->findOrFail($billId);

        $totalAmount = 0.0;
        $paidAmount = 0.0;

        foreach ($bill->accounts as $account) {
            $subtotal = $this->money($account->allocations->sum('allocated_amount'));
            $confirmedPayments = $this->money($account->paymentTransactions->sum('amount'));
            $paid = min($subtotal, $confirmedPayments);
            $outstanding = max(0, $this->money($subtotal - $paid));

            $status = $account->status === 'merged'
                ? 'merged'
                : ($outstanding <= 0 && $subtotal > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'open'));

            $account->forceFill([
                'subtotal_amount' => $subtotal,
                'paid_amount' => $paid,
                'outstanding_amount' => $outstanding,
                'status' => $status,
            ])->save();

            if ($status !== 'merged') {
                $totalAmount += $subtotal;
                $paidAmount += $paid;
            }
        }

        $outstandingAmount = max(0, $this->money($totalAmount - $paidAmount));
        $status = $outstandingAmount <= 0 && $totalAmount > 0
            ? 'settled'
            : ($paidAmount > 0 ? 'settling' : 'open');

        $bill->forceFill([
            'status' => $status,
            'total_amount' => $this->money($totalAmount),
            'paid_amount' => $this->money($paidAmount),
            'outstanding_amount' => $this->money($outstandingAmount),
            'opened_at' => $bill->opened_at ?: now(),
            'closed_at' => $status === 'settled' ? ($bill->closed_at ?: now()) : null,
        ])->save();

        return $bill->fresh([
            'mesa',
            'accounts.allocations.detallePedido.pedido',
            'accounts.allocations.detallePedido.producto',
            'accounts.paymentTransactions' => fn ($query) => $query->where('status', 'confirmed'),
        ]);
    }

    private function syncOrdersForBill(MesaBill $bill, ?User $actor = null): void
    {
        $allocations = $bill->accounts
            ->filter(fn (BillAccount $account) => $account->status !== 'merged')
            ->flatMap(fn (BillAccount $account) => $account->allocations->map(fn (BillAccountAllocation $allocation) => [
                'account' => $account,
                'allocation' => $allocation,
                'detail' => $allocation->detallePedido,
                'order' => $allocation->detallePedido->pedido,
            ]))
            ->filter(fn (array $row) => $row['order'] !== null)
            ->values();

        $orders = $allocations->groupBy(fn (array $row) => $row['order']->id);

        foreach ($orders as $orderRows) {
            /** @var Pedido $order */
            $order = $orderRows->first()['order']->fresh(['detalles.producto.ingredientes', 'mesa']);

            if (!$order || $order->estado === 'pagado') {
                continue;
            }

            $detailIds = $order->detalles->pluck('id');
            $orderAllocations = $allocations->filter(fn (array $row) => $detailIds->contains($row['detail']->id));

            $isFullyAllocated = $order->detalles->every(function (DetallePedido $detail) use ($orderAllocations) {
                return abs(
                    $this->money($orderAllocations
                        ->where('detail.id', $detail->id)
                        ->sum(fn (array $row) => $this->money($row['allocation']->allocated_amount)))
                    - $this->money($detail->subtotal)
                ) < 0.01;
            });

            if (!$isFullyAllocated) {
                continue;
            }

            $isFullyPaid = $orderAllocations->every(fn (array $row) => $row['account']->status === 'paid');

            if (!$isFullyPaid) {
                continue;
            }

            $methods = $bill->paymentTransactions()
                ->where('status', 'confirmed')
                ->whereIn('bill_account_id', $orderAllocations->pluck('account.id')->unique()->all())
                ->pluck('method')
                ->filter()
                ->unique()
                ->values();

            $order->update([
                'metodo_pago' => $methods->count() === 1 ? $methods->first() : 'split',
            ]);

            $this->orderLifecycle->transition($order, 'pagado', $actor, 'Split bill liquidado');
        }
    }

    private function materializeWholeBillPayment(PaymentTransaction $payment, ?User $actor = null): void
    {
        $bill = $this->recalculateBill($payment->mesa_bill_id);

        foreach ($bill->accounts->filter(fn (BillAccount $account) => $account->status !== 'paid' && $account->status !== 'merged') as $account) {
            $amount = $this->money($account->outstanding_amount);

            if ($amount <= 0) {
                continue;
            }

            PaymentTransaction::create([
                'pedido_id' => null,
                'mesa_id' => $payment->mesa_id,
                'mesa_bill_id' => $bill->id,
                'bill_account_id' => $account->id,
                'initiated_by' => $payment->initiated_by,
                'confirmed_by' => $actor?->id ?? $payment->confirmed_by,
                'amount' => $amount,
                'method' => $payment->method,
                'status' => 'confirmed',
                'reference' => ($payment->reference ?: 'BILL-' . $bill->id) . '-A' . $account->id,
                'confirmed_at' => $payment->confirmed_at ?: now(),
                'notes' => 'Generado desde cobro total de la cuenta',
            ]);
        }
    }

    private function payableOrdersForMesa(Mesa $mesa): Collection
    {
        return Pedido::with(['detalles.producto', 'tableSession'])
            ->where('mesa_id', $mesa->id)
            ->whereIn('estado', ['listo', 'servido'])
            ->orderBy('created_at')
            ->get();
    }

    private function payload(MesaBill $bill): array
    {
        $accounts = $bill->accounts
            ->filter(fn (BillAccount $account) => $account->status !== 'merged')
            ->sortBy('sort_order')
            ->values();

        $lineItems = $accounts->flatMap(function (BillAccount $account) {
            return $account->allocations->map(function (BillAccountAllocation $allocation) use ($account) {
                $detail = $allocation->detallePedido;
                $order = $detail?->pedido;

                return [
                    'allocation_id' => $allocation->id,
                    'detail_id' => $detail?->id,
                    'order_id' => $order?->id,
                    'bill_account_id' => $account->id,
                    'account_display_name' => $account->display_name,
                    'account_status' => $account->status,
                    'source_label' => $order?->nombre_cliente ?: ($order?->table_session_id ? $account->display_name : 'Cuenta comun'),
                    'product_name' => $detail?->producto?->nombre,
                    'quantity' => $detail?->cantidad,
                    'detail_subtotal' => $this->money($detail?->subtotal),
                    'allocated_amount' => $this->money($allocation->allocated_amount),
                    'allocation_type' => $allocation->allocation_type,
                    'can_edit' => !in_array($account->status, ['paid', 'merged'], true) && $this->money($account->paid_amount) <= 0,
                ];
            });
        })->values();

        return [
            'bill' => [
                'id' => $bill->id,
                'mesa_id' => $bill->mesa_id,
                'status' => $bill->status,
                'total_amount' => $this->money($bill->total_amount),
                'paid_amount' => $this->money($bill->paid_amount),
                'outstanding_amount' => $this->money($bill->outstanding_amount),
                'opened_at' => optional($bill->opened_at)->toIso8601String(),
                'closed_at' => optional($bill->closed_at)->toIso8601String(),
                'can_reset' => $this->money($bill->paid_amount) <= 0,
            ],
            'accounts' => $accounts->map(function (BillAccount $account) {
                return [
                    'id' => $account->id,
                    'table_session_id' => $account->table_session_id,
                    'display_name' => $account->display_name,
                    'owner_type' => $account->owner_type,
                    'status' => $account->status,
                    'subtotal_amount' => $this->money($account->subtotal_amount),
                    'paid_amount' => $this->money($account->paid_amount),
                    'outstanding_amount' => $this->money($account->outstanding_amount),
                    'sort_order' => $account->sort_order,
                    'items' => $account->allocations->map(function (BillAccountAllocation $allocation) {
                        return [
                            'allocation_id' => $allocation->id,
                            'detail_id' => $allocation->detalle_pedido_id,
                            'product_name' => $allocation->detallePedido?->producto?->nombre,
                            'quantity' => $allocation->detallePedido?->cantidad,
                            'allocated_amount' => $this->money($allocation->allocated_amount),
                            'allocation_type' => $allocation->allocation_type,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'line_items' => $lineItems->all(),
            'groups' => $lineItems->groupBy('source_label')->map(fn (Collection $items, string $label) => [
                'label' => $label,
                'items' => $items->values()->all(),
            ])->values()->all(),
        ];
    }

    private function nextSortOrder(MesaBill $bill): int
    {
        return (int) BillAccount::where('mesa_bill_id', $bill->id)->max('sort_order') + 1;
    }

    private function ratioForAmount(?DetallePedido $detail, float $amount): ?float
    {
        $subtotal = $this->money($detail?->subtotal);

        if ($subtotal <= 0) {
            return null;
        }

        return round($amount / $subtotal, 4);
    }

    private function publishUpdate(?int $mesaId, int $billId, string $type): void
    {
        if (!$mesaId) {
            return;
        }

        $this->realtime->publish(
            type: $type,
            payload: [
                'mesa_id' => $mesaId,
                'mesa_bill_id' => $billId,
            ],
            channels: ['global', 'role_admin', 'role_cashier', 'role_waiter'],
            aggregateId: 'split-bill:' . $billId
        );
    }

    private function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }
}

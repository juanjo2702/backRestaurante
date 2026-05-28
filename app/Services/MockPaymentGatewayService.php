<?php

namespace App\Services;

use App\Models\PaymentGatewayAttempt;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockPaymentGatewayService
{
    private const CACHE_PREFIX = 'mock_checkout:';
    private const TOKEN_TTL_MINUTES = 10;

    private const OUTCOME_MAP = [
        '4111111111111111' => 'success',
        '4000000000000002' => 'declined',
        '4000000000009995' => 'insufficient_funds',
        '4000000000000127' => 'timeout',
    ];

    public function __construct(
        private readonly PaymentService $paymentService,
    ) {
    }

    public function createCheckoutSession(PaymentTransaction $payment): array
    {
        if (!in_array($payment->status, ['pending', 'client_paid'], true)) {
            throw new \RuntimeException('El pago ya no admite checkout simulado');
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);

        Cache::put(
            self::CACHE_PREFIX . $token,
            [
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            $expiresAt
        );

        return [
            'checkout_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'amount' => (float) $payment->amount,
            'payment_id' => $payment->id,
        ];
    }

    public function submitCheckout(array $payload, ?User $actor = null): array
    {
        $cacheKey = self::CACHE_PREFIX . $payload['checkout_token'];
        $session = Cache::pull($cacheKey);

        if (!$session) {
            throw new \RuntimeException('La sesion de checkout expiro o ya fue usada');
        }

        $payment = PaymentTransaction::with(['mesa', 'pedido'])->findOrFail($session['payment_id']);

        if ((float) $payment->amount !== (float) $session['amount']) {
            throw new \RuntimeException('El monto del checkout ya no coincide con la transaccion');
        }

        $outcome = self::OUTCOME_MAP[$payload['pan']] ?? 'declined';
        $brand = $this->detectCardBrand($payload['pan']);
        $last4 = substr($payload['pan'], -4);
        $tokenHash = hash('sha256', $payload['checkout_token']);

        return DB::transaction(function () use ($payment, $payload, $actor, $outcome, $brand, $last4, $tokenHash) {
            $authorizationAttempt = $this->createAttempt(
                payment: $payment,
                stage: 'authorization',
                outcome: $outcome,
                brand: $brand,
                last4: $last4,
                tokenHash: $tokenHash,
                payload: [
                    'cardholder_name' => $payload['cardholder_name'],
                    'card_brand' => $brand,
                    'card_last4' => $last4,
                    'outcome' => $outcome,
                ],
                successful: $outcome === 'success'
            );

            if ($outcome !== 'success') {
                return [
                    'outcome' => $outcome,
                    'message' => $this->outcomeMessage($outcome),
                    'retryable' => $outcome === 'timeout',
                    'payment' => $payment->fresh(),
                    'order_updates' => $this->orderUpdatesFor($payment->fresh()),
                    'gateway_attempt' => $authorizationAttempt,
                ];
            }

            $captureAttempt = $this->createAttempt(
                payment: $payment,
                stage: 'capture',
                outcome: 'success',
                brand: $brand,
                last4: $last4,
                tokenHash: $tokenHash,
                payload: [
                    'captured_amount' => (float) $payment->amount,
                    'card_brand' => $brand,
                    'card_last4' => $last4,
                    'outcome' => 'success',
                ],
                successful: true
            );

            $payment->update([
                'method' => 'card',
                'status' => 'pending',
                'notes' => trim(($payment->notes ? $payment->notes . PHP_EOL : '') . 'Mock gateway success'),
            ]);

            $confirmedPayment = $this->paymentService->confirm($payment->fresh(), $actor);

            return [
                'outcome' => 'success',
                'message' => $this->outcomeMessage('success'),
                'retryable' => false,
                'payment' => $confirmedPayment,
                'order_updates' => $this->orderUpdatesFor($confirmedPayment),
                'gateway_attempt' => $captureAttempt,
                'authorization_attempt' => $authorizationAttempt,
            ];
        });
    }

    private function createAttempt(
        PaymentTransaction $payment,
        string $stage,
        string $outcome,
        string $brand,
        string $last4,
        string $tokenHash,
        array $payload,
        bool $successful
    ): PaymentGatewayAttempt {
        return PaymentGatewayAttempt::create([
            'payment_transaction_id' => $payment->id,
            'provider' => 'mock_gateway',
            'stage' => $stage,
            'outcome' => $outcome,
            'gateway_reference' => strtoupper($stage) . '-' . Str::upper(Str::random(10)),
            'authorization_code' => $successful ? Str::upper(Str::random(6)) : null,
            'card_brand' => $brand,
            'card_last4' => $last4,
            'request_token_hash' => $tokenHash,
            'response_payload' => $payload,
            'processed_at' => now(),
        ]);
    }

    private function detectCardBrand(string $pan): string
    {
        return match (true) {
            str_starts_with($pan, '4') => 'visa',
            str_starts_with($pan, '5') => 'mastercard',
            default => 'test_card',
        };
    }

    private function outcomeMessage(string $outcome): string
    {
        return match ($outcome) {
            'success' => 'Pago aprobado y capturado correctamente',
            'timeout' => 'La pasarela no respondio a tiempo. Puedes reintentar',
            'insufficient_funds' => 'La tarjeta no tiene fondos suficientes',
            default => 'La tarjeta fue rechazada por la pasarela simulada',
        };
    }

    private function orderUpdatesFor(PaymentTransaction $payment): array
    {
        $orders = collect();

        if ($payment->pedido) {
            $orders->push($payment->pedido->fresh());
        }

        if ($payment->mesa) {
            $orders = $orders->merge(
                $payment->mesa->pedidos()->where('estado', 'pagado')->latest()->take(5)->get()
            );
        }

        return $orders->unique('id')->map(fn ($order) => [
            'id' => $order->id,
            'estado' => $order->estado,
            'fecha_pago' => $order->fecha_pago,
            'metodo_pago' => $order->metodo_pago,
        ])->values()->all();
    }
}

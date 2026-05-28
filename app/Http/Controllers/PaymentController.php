<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use App\Models\PaymentTransaction;
use App\Services\MockPaymentGatewayService;
use App\Services\PaymentService;
use App\Services\PublicTableSessionService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function createIntent(Request $request, PaymentService $paymentService)
    {
        $validated = $request->validate([
            'mesa_id' => 'nullable|required_without:bill_account_id|exists:mesas,id',
            'bill_account_id' => 'nullable|required_without:mesa_id|exists:bill_accounts,id',
            'method' => 'required|string|in:cash,card,qr',
        ]);

        try {
            if (!empty($validated['bill_account_id'])) {
                $payment = $paymentService->createIntentForBillAccount(
                    (int) $validated['bill_account_id'],
                    $validated['method'],
                    $request->user()
                );
            } else {
                $payment = $paymentService->createIntentForTable(
                    Mesa::findOrFail($validated['mesa_id']),
                    $validated['method'],
                    $request->user()
                );
            }
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($payment, 201);
    }

    public function markClientPaid(Request $request, PaymentTransaction $payment, PaymentService $paymentService)
    {
        $payment = $paymentService->markClientPaid($payment, $request->user());

        return response()->json($payment);
    }

    public function confirm(Request $request, PaymentTransaction $payment, PaymentService $paymentService)
    {
        $payment = $paymentService->confirm($payment, $request->user());

        return response()->json($payment);
    }

    public function createMockCheckoutSession(PaymentTransaction $payment, MockPaymentGatewayService $mockPaymentGatewayService)
    {
        if ($payment->method !== 'card') {
            $payment->update(['method' => 'card']);
        }

        return response()->json($mockPaymentGatewayService->createCheckoutSession($payment));
    }

    public function submitMockCheckout(Request $request, MockPaymentGatewayService $mockPaymentGatewayService)
    {
        $validated = $request->validate([
            'checkout_token' => 'required|string',
            'cardholder_name' => 'required|string|max:255',
            'pan' => 'required|string|min:13|max:19',
            'expiry_month' => 'required|string|max:2',
            'expiry_year' => 'required|string|max:4',
            'cvv' => 'required|string|min:3|max:4',
        ]);

        try {
            $result = $mockPaymentGatewayService->submitCheckout($validated, $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json([
                'outcome' => 'declined',
                'message' => $exception->getMessage(),
                'retryable' => false,
            ], 422);
        }

        return response()->json($result);
    }

    public function createPublicMockCheckoutSession(
        Request $request,
        PaymentTransaction $payment,
        MockPaymentGatewayService $mockPaymentGatewayService,
        PublicTableSessionService $publicTableSessionService
    ) {
        if (!$payment->mesa) {
            return response()->json(['message' => 'La transaccion no esta asociada a una mesa'], 409);
        }

        try {
            $publicTableSessionService->resolveSession(
                $payment->mesa,
                $publicTableSessionService->extractSessionToken($request)
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        if ($payment->method !== 'card') {
            $payment->update(['method' => 'card']);
        }

        return response()->json($mockPaymentGatewayService->createCheckoutSession($payment));
    }
}

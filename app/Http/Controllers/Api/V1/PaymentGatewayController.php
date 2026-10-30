<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SalesAndPos\Actions\CreatePaymentTransactionAction;
use App\Domains\SalesAndPos\Gateways\PaymentGatewayManager;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\PaymentTransaction;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\GeneratePaymentQrRequest;
use App\Http\Requests\V1\SimulatePaymentWebhookRequest;
use App\Http\Resources\V1\PaymentTransactionResource;
use Illuminate\Http\JsonResponse;

class PaymentGatewayController extends Controller
{
    /**
     * Generate dynamic MMQR or deeplink payment intent for customer display.
     */
    public function generateQr(GeneratePaymentQrRequest $request, CreatePaymentTransactionAction $action): JsonResponse
    {
        $sessionId = $request->validated('pos_session_id');
        $transaction = $action->execute($request->validated(), $sessionId);

        return response()->json([
            'success' => true,
            'message' => "Payment intent generated via {$transaction->provider}.",
            'data' => new PaymentTransactionResource($transaction),
        ], 201);
    }

    /**
     * Check transaction status by transaction number or gateway reference.
     */
    public function status(string $transactionNo): JsonResponse
    {
        $transaction = PaymentTransaction::where('transaction_no', $transactionNo)
            ->orWhere('gateway_ref', $transactionNo)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new PaymentTransactionResource($transaction),
        ]);
    }

    /**
     * Receive verified gateway webhook callback to update transaction and order payment splits.
     */
    public function handleWebhook(SimulatePaymentWebhookRequest $request, PaymentGatewayManager $gatewayManager): JsonResponse
    {
        $driver = $gatewayManager->driver($request->validated('provider'));
        $verified = $driver->verifyWebhook($request->validated());

        $transaction = PaymentTransaction::where('gateway_ref', $request->validated('gateway_ref'))->first();

        if ($transaction) {
            $transaction->update([
                'status' => $verified['status'],
                'metadata' => array_merge($transaction->metadata ?? [], ['webhook' => $verified]),
            ]);

            // If transaction is linked to a sales order, update payment splits matching provider
            if ($transaction->sales_order_id && $verified['success']) {
                PaymentSplit::where('sales_order_id', $transaction->sales_order_id)
                    ->where('gateway_provider', $transaction->provider)
                    ->update([
                        'status' => 'completed',
                        'reference_no' => $verified['transaction_id'] ?? $transaction->gateway_ref,
                    ]);
            }
        }

        AuditLog::create([
            'staff_id' => null,
            'module' => 'payments',
            'action' => 'webhook_processed',
            'severity' => $verified['success'] ? 'info' : 'warning',
            'resource_type' => 'PaymentTransaction',
            'resource_id' => $transaction?->id,
            'description' => "Cryptographically verified external payment webhook processed from {$request->validated('provider')} for ref: {$request->validated('gateway_ref')}",
            'diffs' => [
                'provider' => $request->validated('provider'),
                'status' => $verified['status'],
                'gateway_ref' => $request->validated('gateway_ref'),
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Webhook processed. Status: {$verified['status']}.",
            'verified' => $verified,
            'transaction' => $transaction ? new PaymentTransactionResource($transaction) : null,
        ]);
    }

    /**
     * Alias for simulateWebhook backwards compatibility
     */
    public function simulateWebhook(SimulatePaymentWebhookRequest $request, PaymentGatewayManager $gatewayManager): JsonResponse
    {
        return $this->handleWebhook($request, $gatewayManager);
    }
}

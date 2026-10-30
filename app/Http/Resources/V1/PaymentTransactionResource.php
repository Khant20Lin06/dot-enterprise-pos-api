<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transactionNo' => $this->transaction_no,
            'posSessionId' => $this->pos_session_id,
            'salesOrderId' => $this->sales_order_id,
            'provider' => $this->provider,
            'paymentMethod' => $this->payment_method,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'qrPayload' => $this->qr_payload,
            'deeplink' => $this->metadata['deeplink'] ?? null,
            'status' => $this->status,
            'gatewayRef' => $this->gateway_ref,
            'errorMessage' => $this->error_message,
            'metadata' => $this->metadata,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

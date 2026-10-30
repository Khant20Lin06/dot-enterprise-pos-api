<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receiptNo' => $this->receipt_no,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'dueAmount' => (float) $this->customer->due_amount,
            ] : null,
            'amount' => (float) $this->amount,
            'tenderedAmount' => (float) $this->tendered_amount,
            'changeAmount' => (float) $this->change_amount,
            'paymentMethod' => $this->payment_method,
            'note' => $this->note,
            'cashier' => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null,
            'posSessionId' => $this->pos_session_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNo' => $this->order_no,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'currentDue' => (float) $this->customer->current_due,
            ] : null,
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'licensePlate' => $this->vehicle->license_plate,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
            ] : null,
            'cashier' => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null,
            'subtotal' => (float) $this->subtotal,
            'laborTotal' => (float) $this->labor_total,
            'discountAmount' => (float) $this->discount_amount,
            'taxAmount' => (float) $this->tax_amount,
            'totalAmount' => (float) $this->total_amount,
            'paidAmount' => (float) $this->paid_amount,
            'dueAmount' => (float) $this->due_amount,
            'pointsEarned' => (int) $this->points_earned,
            'pointsRedeemed' => (int) $this->points_redeemed,
            'status' => $this->status,
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productOptionId' => $item->product_option_id,
                    'name' => $item->name,
                    'qty' => (int) $item->qty,
                    'unitPrice' => (float) $item->unit_price,
                    'discountAmount' => (float) $item->discount_amount,
                    'subtotal' => (float) $item->subtotal,
                ];
            }),
            'payments' => $this->payments->map(function ($split) {
                return [
                    'id' => $split->id,
                    'method' => $split->method,
                    'amount' => (float) $split->amount,
                    'referenceNo' => $split->reference_no,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

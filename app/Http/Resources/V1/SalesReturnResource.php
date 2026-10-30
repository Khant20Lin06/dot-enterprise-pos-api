<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'returnNo' => $this->return_no,
            'salesOrder' => $this->salesOrder ? [
                'id' => $this->salesOrder->id,
                'orderNo' => $this->salesOrder->order_no,
                'totalAmount' => (float) $this->salesOrder->total_amount,
                'status' => $this->salesOrder->status,
            ] : null,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'currentDue' => (float) $this->customer->current_due,
                'loyaltyPoints' => (int) $this->customer->loyalty_points,
            ] : null,
            'cashier' => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null,
            'totalRefundAmount' => (float) $this->total_refund_amount,
            'refundMethod' => $this->refund_method,
            'refundReference' => $this->refund_reference,
            'reason' => $this->reason,
            'status' => $this->status,
            'pointsDeducted' => (int) $this->points_deducted,
            'notes' => $this->notes,
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'salesOrderItemId' => $item->sales_order_item_id,
                    'productId' => $item->product_id,
                    'productName' => $item->product?->name ?? 'Product',
                    'productBarcode' => $item->product?->barcode ?? null,
                    'productOptionId' => $item->product_option_id,
                    'qty' => (int) $item->qty,
                    'unitPrice' => (float) $item->unit_price,
                    'refundSubtotal' => (float) $item->refund_subtotal,
                    'itemCondition' => $item->item_condition,
                    'restocked' => (bool) $item->restocked,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

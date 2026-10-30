<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'poNo' => $this->po_no,
            'supplierId' => $this->supplier_id,
            'supplier' => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'phone' => $this->supplier->phone,
            ] : null,
            'totalAmount' => (float) $this->total_amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productName' => $item->product?->name,
                    'qty' => (int) $item->qty,
                    'unitPrice' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'productBarcode' => $this->product?->barcode,
            'type' => $this->type,
            'prevStock' => (int) $this->prev_stock,
            'adjustedQty' => (int) $this->adjusted_qty,
            'newStock' => (int) $this->new_stock,
            'reason' => $this->reason,
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

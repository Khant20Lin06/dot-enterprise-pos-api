<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouseId' => $this->warehouse_id,
            'warehouseName' => $this->warehouse?->name,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'barcode' => $this->product->barcode,
                'sellPrice' => (float) $this->product->sell_price,
                'totalCompanyStock' => (int) $this->product->stock,
            ] : null,
            'productOptionId' => $this->product_option_id,
            'stockQty' => (int) $this->stock_qty,
            'minAlertQty' => (int) $this->min_alert_qty,
            'binLocation' => $this->bin_location,
            'isLowStock' => $this->stock_qty <= $this->min_alert_qty,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

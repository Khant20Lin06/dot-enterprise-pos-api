<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUomConversionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'productId' => (string) $this->product_id,
            'uomId' => (string) $this->uom_id,
            'uomName' => $this->uom?->name ?? 'Unit',
            'unitName' => $this->unit_name,
            'conversionFactor' => (float) $this->conversion_factor,
            'barcode' => $this->barcode,
            'sku' => $this->sku,
            'costPrice' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'sellPrice' => (float) $this->sell_price,
            'isPurchaseDefault' => (bool) $this->is_purchase_default,
            'isSaleDefault' => (bool) $this->is_sale_default,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

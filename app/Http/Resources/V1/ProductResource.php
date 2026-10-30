<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'code' => $this->category->code,
            ] : null,
            'unit' => $this->unitOfMeasure?->code ?? 'PCS',
            'retailPrice' => (float) $this->retail_price,
            'wholesalePrice' => (float) $this->wholesale_price,
            'costPrice' => $request->user()?->hasPermission('inventory', 'viewCostPrice') ? (float) $this->cost_price : null,
            'stock' => (float) $this->stock,
            'minStockAlert' => (float) $this->min_stock_alert,
            'isLowStock' => ! $this->is_labor && ($this->stock <= $this->min_stock_alert),
            'isLabor' => (bool) $this->is_labor,
            'isActive' => (bool) $this->is_active,
            'packagingUnits' => $this->relationLoaded('uomConversions') ? ProductUomConversionResource::collection($this->uomConversions) : [],
            'vehicleFitments' => $this->relationLoaded('vehicleFitments') ? ProductVehicleFitmentResource::collection($this->vehicleFitments) : [],
            'options' => $this->options ? $this->options->map(function ($option) {
                return [
                    'id' => $option->id,
                    'sku' => $option->sku,
                    'barcode' => $option->barcode,
                    'name' => $option->name,
                    'priceModifier' => (float) $option->price_modifier,
                    'stock' => (float) $option->stock,
                ];
            }) : [],
        ];
    }
}

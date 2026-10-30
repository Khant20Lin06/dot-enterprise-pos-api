<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => (float) $this->price,
            'costPrice' => (float) $this->cost_price,
            'stock' => (int) $this->stock,
            'imageUrl' => $this->image_url,
            'isActive' => (bool) $this->is_active,
            'attributes' => $this->attributeValues->map(function ($val) {
                return [
                    'attributeId' => $val->attribute_id,
                    'attributeCode' => $val->attribute?->code,
                    'attributeName' => $val->attribute?->name,
                    'valueId' => $val->id,
                    'value' => $val->value,
                    'colorHex' => $val->color_hex,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

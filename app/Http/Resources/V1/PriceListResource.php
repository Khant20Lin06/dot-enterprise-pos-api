<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'isDefault' => (bool) $this->is_default,
            'isActive' => (bool) $this->is_active,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'itemsCount' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productName' => $item->product?->name,
                    'minQty' => $item->min_qty,
                    'pricingMethod' => $item->pricing_method,
                    'value' => (float) $item->value,
                ]);
            }),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

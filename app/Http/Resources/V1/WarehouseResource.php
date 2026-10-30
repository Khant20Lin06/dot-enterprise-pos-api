<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'phone' => $this->phone,
            'isCentral' => (bool) $this->is_central,
            'isActive' => (bool) $this->is_active,
            'totalSkuCount' => $this->whenLoaded('stocks', fn () => $this->stocks->count()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

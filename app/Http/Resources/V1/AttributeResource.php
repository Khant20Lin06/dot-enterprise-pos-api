<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'values' => $this->values->map(function ($val) {
                return [
                    'id' => $val->id,
                    'value' => $val->value,
                    'colorHex' => $val->color_hex,
                    'sortOrder' => $val->sort_order,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

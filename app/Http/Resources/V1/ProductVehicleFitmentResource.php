<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVehicleFitmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $yearRange = null;
        if ($this->year_from || $this->year_to) {
            $yearRange = ($this->year_from ?? 'Pre') . ' - ' . ($this->year_to ?? 'Present');
        }

        return [
            'id' => (string) $this->id,
            'productId' => (string) $this->product_id,
            'make' => $this->make,
            'model' => $this->model,
            'yearFrom' => $this->year_from,
            'yearTo' => $this->year_to,
            'yearRange' => $yearRange,
            'engineCode' => $this->engine_code,
            'chassisCode' => $this->chassis_code,
            'position' => $this->position,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

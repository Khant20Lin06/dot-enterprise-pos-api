<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetResource extends JsonResource
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
            'name' => $this->name,
            'type' => $this->type,
            'creditLimit' => (float) $this->credit_limit,
            'notes' => $this->notes,
            'customersCount' => $this->whenCounted('customers'),
            'vehiclesCount' => $this->whenCounted('vehicles'),
            'customers' => CustomerResource::collection($this->whenLoaded('customers')),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

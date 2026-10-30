<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'creditLimit' => (float) $this->credit_limit,
            'currentDue' => (float) $this->current_due,
            'availableCredit' => (float) max(0, $this->credit_limit - $this->current_due),
            'loyaltyPoints' => (int) $this->loyalty_points,
            'fleet' => $this->fleet ? [
                'id' => $this->fleet->id,
                'name' => $this->fleet->name,
                'discountPercent' => (float) $this->fleet->discount_percent,
            ] : null,
            'vehicles' => $this->vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->id,
                    'licensePlate' => $vehicle->license_plate,
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year,
                    'vin' => $vehicle->vin,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

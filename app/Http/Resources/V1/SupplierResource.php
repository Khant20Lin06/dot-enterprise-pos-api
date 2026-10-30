<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'contactPerson' => $this->contact_person,
            'address' => $this->address,
            'outstandingBalance' => (float) $this->outstanding_balance,
            'productsCount' => $this->whenCounted('products'),
            'purchasesCount' => $this->whenCounted('purchases'),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

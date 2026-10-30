<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MechanicResource extends JsonResource
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
            'phone' => $this->phone,
            'specialization' => $this->specialization,
            'commissionRate' => (float) $this->commission_rate,
            'status' => $this->status,
            'avatar' => $this->avatar,
            'pendingCommissionTotal' => $this->pendingCommissionTotal(),
            'totalCommissionsEarned' => (float) $this->commissions()->sum('earned_amount'),
            'activeWorkOrdersCount' => $this->workOrders()->whereNotIn('status', ['Completed / Invoiced', 'Cancelled'])->count(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

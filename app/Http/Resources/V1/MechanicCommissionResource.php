<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MechanicCommissionResource extends JsonResource
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
            'mechanicId' => $this->mechanic_id,
            'workOrderId' => $this->work_order_id,
            'jobCardNo' => $this->workOrder?->job_card_no,
            'laborAmount' => (float) $this->labor_amount,
            'commissionRate' => (float) $this->commission_rate,
            'earnedAmount' => (float) $this->earned_amount,
            'status' => $this->status,
            'paidAt' => $this->paid_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}

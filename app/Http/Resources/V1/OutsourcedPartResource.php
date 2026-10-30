<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourcedPartResource extends JsonResource
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
            'workOrderId' => $this->work_order_id,
            'jobCardNo' => $this->workOrder?->job_card_no,
            'supplierId' => $this->supplier_id,
            'supplierName' => $this->supplier?->name,
            'partName' => $this->part_name,
            'costPrice' => (float) $this->cost_price,
            'billPrice' => (float) $this->bill_price,
            'profit' => $this->profit(),
            'status' => $this->status,
            'paymentStatus' => $this->payment_status,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

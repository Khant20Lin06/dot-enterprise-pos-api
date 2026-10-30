<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jobCardNo' => $this->job_card_no,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null,
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'licensePlate' => $this->vehicle->license_plate,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'year' => $this->vehicle->year,
            ] : null,
            'serviceAdvisor' => $this->serviceAdvisor ? [
                'id' => $this->serviceAdvisor->id,
                'name' => $this->serviceAdvisor->name,
            ] : null,
            'primaryMechanic' => $this->primaryMechanic ? [
                'id' => $this->primaryMechanic->id,
                'name' => $this->primaryMechanic->name,
                'specialty' => $this->primaryMechanic->specialty,
            ] : null,
            'status' => $this->status,
            'mileageIn' => $this->mileage_in,
            'fuelLevel' => $this->fuel_level,
            'complaint' => $this->complaint,
            'diagnosis' => $this->diagnosis,
            'partsTotal' => (float) $this->parts_total,
            'laborTotal' => (float) $this->labor_total,
            'grandTotal' => (float) $this->grand_total,
            'salesOrderId' => $this->sales_order_id,
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'itemType' => $item->item_type,
                    'name' => $item->name,
                    'qty' => (int) $item->qty,
                    'unitPrice' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'isApproved' => (bool) $item->is_approved,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
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
            'returnNo' => $this->return_no,
            'purchaseId' => $this->purchase_id,
            'purchaseInvoiceNo' => $this->purchase?->invoice_no,
            'supplierId' => $this->supplier_id,
            'supplierName' => $this->supplier?->name,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'qty' => $this->qty,
            'amount' => (float) $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

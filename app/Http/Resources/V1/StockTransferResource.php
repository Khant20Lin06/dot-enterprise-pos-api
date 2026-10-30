<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transferNo' => $this->transfer_no,
            'sourceWarehouse' => $this->sourceWarehouse ? [
                'id' => $this->sourceWarehouse->id,
                'code' => $this->sourceWarehouse->code,
                'name' => $this->sourceWarehouse->name,
            ] : null,
            'destinationWarehouse' => $this->destinationWarehouse ? [
                'id' => $this->destinationWarehouse->id,
                'code' => $this->destinationWarehouse->code,
                'name' => $this->destinationWarehouse->name,
            ] : null,
            'requester' => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null,
            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null,
            'receiver' => $this->receiver ? [
                'id' => $this->receiver->id,
                'name' => $this->receiver->name,
            ] : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'dispatchedAt' => $this->dispatched_at?->toIso8601String(),
            'receivedAt' => $this->received_at?->toIso8601String(),
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productName' => $item->product?->name ?? 'Product',
                    'productBarcode' => $item->product?->barcode ?? null,
                    'productOptionId' => $item->product_option_id,
                    'qtyRequested' => (int) $item->qty_requested,
                    'qtyDispatched' => (int) $item->qty_dispatched,
                    'qtyReceived' => (int) $item->qty_received,
                    'notes' => $item->notes,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

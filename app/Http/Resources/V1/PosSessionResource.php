<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sessionNo' => $this->session_no,
            'cashier' => [
                'id' => $this->cashier_id,
                'name' => $this->cashier?->name,
                'email' => $this->cashier?->email,
            ],
            'terminalId' => $this->terminal_id,
            'warehouseId' => $this->warehouse_id,
            'warehouseName' => $this->warehouse?->name,
            'openingFloat' => (float) $this->opening_float,
            'status' => $this->status,
            'openedAt' => $this->opened_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'countedCash' => $this->counted_cash !== null ? (float) $this->counted_cash : null,
            'expectedCash' => $this->expected_cash !== null ? (float) $this->expected_cash : null,
            'cashVariance' => $this->cash_variance !== null ? (float) $this->cash_variance : null,
            'financialSummary' => [
                'totalSales' => (float) $this->total_sales,
                'totalRefunds' => (float) $this->total_refunds,
                'cashSales' => (float) $this->cash_sales,
                'kpaySales' => (float) $this->kpay_sales,
                'waveSales' => (float) $this->wave_sales,
                'cardSales' => (float) $this->card_sales,
                'creditSales' => (float) $this->credit_sales,
            ],
            'notes' => [
                'opening' => $this->opening_notes,
                'closing' => $this->closing_notes,
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

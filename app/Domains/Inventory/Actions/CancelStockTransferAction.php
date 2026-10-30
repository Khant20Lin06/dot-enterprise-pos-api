<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelStockTransferAction
{
    /**
     * Cancel a draft or in-transit transfer, reverting dispatched goods if necessary.
     *
     * @throws DomainException
     */
    public function execute(StockTransfer $transfer, int $operatorId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $operatorId) {
            $locked = StockTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'Received') {
                throw new DomainException("Cannot cancel an already completed and received transfer (#{$locked->transfer_no}).");
            }

            if ($locked->status === 'Cancelled') {
                return $locked;
            }

            // If in-transit, revert inventory back to source warehouse
            if ($locked->status === 'In Transit') {
                foreach ($locked->items as $item) {
                    if ($item->qty_dispatched > 0) {
                        WarehouseStock::where('warehouse_id', $locked->source_warehouse_id)
                            ->where('product_id', $item->product_id)
                            ->when($item->product_option_id, fn ($q) => $q->where('product_option_id', $item->product_option_id))
                            ->lockForUpdate()
                            ->increment('stock_qty', $item->qty_dispatched);
                    }
                }
            }

            $locked->update(['status' => 'Cancelled']);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'inventory',
                'action' => 'stock_transfer_cancelled',
                'severity' => 'warning',
                'resource_type' => 'stock_transfers',
                'resource_id' => (string) $locked->id,
                'description' => "Stock transfer #{$locked->transfer_no} cancelled. Goods reverted.",
                'diffs' => [
                    'transfer_no' => $locked->transfer_no,
                    'status' => 'Cancelled',
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh(['sourceWarehouse', 'destinationWarehouse', 'items.product']);
        });
    }
}

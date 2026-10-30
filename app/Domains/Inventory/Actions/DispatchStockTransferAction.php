<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Events\StockTransferDispatchedEvent;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Exceptions\InsufficientStockException;
use DomainException;
use Illuminate\Support\Facades\DB;

class DispatchStockTransferAction
{
    /**
     * Dispatch stock transfer order, deducting source warehouse physical stock with pessimistic locking.
     *
     * @throws DomainException
     * @throws InsufficientStockException
     */
    public function execute(StockTransfer $transfer, int $approvedBy): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $approvedBy) {
            $lockedTransfer = StockTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($lockedTransfer->status !== 'Draft') {
                throw new DomainException("Cannot dispatch transfer #{$lockedTransfer->transfer_no} because it is in '{$lockedTransfer->status}' status.");
            }

            $source = $lockedTransfer->sourceWarehouse;

            // 1. Lock and deduct each item from source warehouse stock
            foreach ($lockedTransfer->items as $item) {
                $whStock = WarehouseStock::where('warehouse_id', $lockedTransfer->source_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->when($item->product_option_id, fn ($q) => $q->where('product_option_id', $item->product_option_id))
                    ->lockForUpdate()
                    ->first();

                $available = $whStock ? $whStock->stock_qty : 0;

                if ($available < $item->qty_requested) {
                    $prodName = $item->product?->name ?? "Product #{$item->product_id}";
                    throw new InsufficientStockException(
                        "Source Warehouse '{$source->name}' has insufficient stock for '{$prodName}'. Available: {$available}, Requested: {$item->qty_requested}."
                    );
                }

                $whStock->decrement('stock_qty', $item->qty_requested);
                $item->update(['qty_dispatched' => $item->qty_requested]);
            }

            // 2. Transition status to In Transit
            $lockedTransfer->update([
                'status' => 'In Transit',
                'dispatched_at' => now(),
                'approved_by' => $approvedBy,
            ]);

            AuditLog::create([
                'staff_id' => $approvedBy,
                'module' => 'inventory',
                'action' => 'stock_transfer_dispatched',
                'severity' => 'info',
                'resource_type' => 'stock_transfers',
                'resource_id' => (string) $lockedTransfer->id,
                'description' => "Transfer {$lockedTransfer->transfer_no} dispatched from '{$source->name}'. Stock in transit.",
                'diffs' => [
                    'transfer_no' => $lockedTransfer->transfer_no,
                    'status' => 'In Transit',
                    'dispatched_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            event(new StockTransferDispatchedEvent($lockedTransfer));

            return $lockedTransfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items.product', 'requester', 'approver']);
        });
    }
}

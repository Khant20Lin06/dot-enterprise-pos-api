<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Events\StockTransferDispatchedEvent;
use App\Domains\Inventory\Models\Product;
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
     * Enforces consistent global lock hierarchy (Product Master -> WarehouseStock) in ascending ID order
     * to eliminate database deadlocks with concurrent POS Checkouts.
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

            // Sort transfer items in deterministic ascending product_id order to eliminate circular lock waits
            $sortedItems = $lockedTransfer->items->sortBy('product_id');

            // 1. Strict Global Lock Acquisition: Lock Product master row THEN WarehouseStock row
            foreach ($sortedItems as $item) {
                // Lock 1: Product Master row
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();

                // Lock 2: Warehouse Stock row
                $whStock = WarehouseStock::where('warehouse_id', $lockedTransfer->source_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->when($item->product_option_id, fn ($q) => $q->where('product_option_id', $item->product_option_id))
                    ->lockForUpdate()
                    ->first();

                $available = $whStock ? $whStock->stock_qty : 0;

                if ($available < $item->qty_requested) {
                    $prodName = $item->product?->name ?? $product->name ?? "Product #{$item->product_id}";
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
                'description' => "Stock transfer #{$lockedTransfer->transfer_no} dispatched from {$source->name}",
                'diffs' => [
                    'transfer_no' => $lockedTransfer->transfer_no,
                    'status' => 'In Transit',
                    'dispatched_items' => $lockedTransfer->items->count(),
                ],
                'created_at' => now(),
            ]);

            event(new StockTransferDispatchedEvent($lockedTransfer));

            return $lockedTransfer->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'approver']);
        });
    }
}

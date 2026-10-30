<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Events\StockTransferReceivedEvent;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReceiveStockTransferAction
{
    /**
     * Receive in-transit stock transfer at destination warehouse, incrementing physical location inventory.
     * Enforces global ascending lock order and accounts for damaged/lost-in-transit items (MED-03).
     *
     * @param  array<int, int>|null  $receivedQuantities  [item_id => qty_received]
     * @throws DomainException
     */
    public function execute(StockTransfer $transfer, int $receivedBy, ?array $receivedQuantities = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedBy, $receivedQuantities) {
            $lockedTransfer = StockTransfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ($lockedTransfer->status !== 'In Transit') {
                throw new DomainException("Cannot receive transfer #{$lockedTransfer->transfer_no} because it is in '{$lockedTransfer->status}' status.");
            }

            $dest = $lockedTransfer->destinationWarehouse;

            // Sort transfer items deterministically by product_id ascending
            $sortedItems = $lockedTransfer->items->sortBy('product_id');

            // 1. Lock and increment each item in destination warehouse stock
            foreach ($sortedItems as $item) {
                // Global lock order: Lock Product master row first
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();

                $qtyToReceive = isset($receivedQuantities[$item->id])
                    ? (int) $receivedQuantities[$item->id]
                    : $item->qty_dispatched;

                if ($qtyToReceive < 0 || $qtyToReceive > $item->qty_dispatched) {
                    throw new DomainException("Received quantity for item #{$item->id} cannot exceed dispatched quantity ({$item->qty_dispatched}).");
                }

                $whStock = WarehouseStock::firstOrCreate(
                    [
                        'warehouse_id' => $lockedTransfer->destination_warehouse_id,
                        'product_id' => $item->product_id,
                        'product_option_id' => $item->product_option_id,
                    ],
                    ['stock_qty' => 0]
                );

                WarehouseStock::where('id', $whStock->id)
                    ->lockForUpdate()
                    ->increment('stock_qty', $qtyToReceive);

                if ($item->product_variant_id) {
                    $variant = ProductVariant::where('id', $item->product_variant_id)->lockForUpdate()->first();
                    if ($variant) {
                        $variant->increment('stock', $qtyToReceive);
                    }
                }

                $item->update(['qty_received' => $qtyToReceive]);

                // MED-03 Hardening: Track lost-in-transit goods and deduct from master company assets
                $lostQty = $item->qty_dispatched - $qtyToReceive;
                if ($lostQty > 0) {
                    StockAdjustment::create([
                        'product_id' => $item->product_id,
                        'type' => 'Damage / Scrap',
                        'prev_stock' => $product->stock,
                        'adjusted_qty' => -$lostQty,
                        'new_stock' => $product->stock - $lostQty,
                        'reason' => "Transfer #{$lockedTransfer->transfer_no} lost in transit (Dispatched: {$item->qty_dispatched}, Received: {$qtyToReceive})",
                        'staff_id' => $receivedBy,
                    ]);

                    $product->decrement('stock', $lostQty);
                }
            }

            // 2. Transition status to Received
            $lockedTransfer->update([
                'status' => 'Received',
                'received_at' => now(),
                'received_by' => $receivedBy,
            ]);

            AuditLog::create([
                'staff_id' => $receivedBy,
                'module' => 'inventory',
                'action' => 'stock_transfer_received',
                'severity' => 'info',
                'resource_type' => 'stock_transfers',
                'resource_id' => (string) $lockedTransfer->id,
                'description' => "Stock transfer #{$lockedTransfer->transfer_no} received at {$dest->name}",
                'diffs' => [
                    'transfer_no' => $lockedTransfer->transfer_no,
                    'status' => 'Received',
                    'received_items' => $lockedTransfer->items->count(),
                ],
                'created_at' => now(),
            ]);

            event(new StockTransferReceivedEvent($lockedTransfer));

            return $lockedTransfer->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'receiver']);
        });
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Events\StockTransferReceivedEvent;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReceiveStockTransferAction
{
    /**
     * Receive in-transit stock transfer at destination warehouse, incrementing physical location inventory.
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

            // 1. Lock and increment each item in destination warehouse stock
            foreach ($lockedTransfer->items as $item) {
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

                $item->update(['qty_received' => $qtyToReceive]);
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
                'description' => "Transfer {$lockedTransfer->transfer_no} received at '{$dest->name}'. Inventory restocked.",
                'diffs' => [
                    'transfer_no' => $lockedTransfer->transfer_no,
                    'destination' => $dest->name,
                    'status' => 'Received',
                    'received_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            event(new StockTransferReceivedEvent($lockedTransfer));

            return $lockedTransfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items.product', 'requester', 'approver', 'receiver']);
        });
    }
}

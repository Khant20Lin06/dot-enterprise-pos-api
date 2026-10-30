<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReceivePurchaseAction
{
    /**
     * Receive goods from a Purchase Order (Goods Receipt Note - GRN),
     * supporting full or partial shipments, automatically recalculating Moving Average Cost,
     * updating physical warehouse stock and master inventory with row-level locking,
     * and crediting supplier payables for received goods.
     *
     * @param  int  $purchaseId
     * @param  int|null  $warehouseId
     * @param  int|null  $operatorId
     * @param  array<int, array{purchase_item_id: int, received_qty: int}>|null  $itemsPayload
     * @return Purchase
     * @throws DomainException
     */
    public function execute(
        int $purchaseId,
        ?int $warehouseId = null,
        ?int $operatorId = null,
        ?array $itemsPayload = null
    ): Purchase {
        return DB::transaction(function () use ($purchaseId, $warehouseId, $operatorId, $itemsPayload) {
            $purchase = Purchase::with(['items.product', 'supplier'])
                ->where('id', $purchaseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status === 'Received') {
                throw new DomainException("Purchase Order #{$purchase->po_no} has already been completely received into inventory.");
            }

            if ($purchase->status === 'Cancelled') {
                throw new DomainException("Cannot receive cancelled Purchase Order #{$purchase->po_no}.");
            }

            $targetWarehouse = $warehouseId
                ? Warehouse::findOrFail($warehouseId)
                : (Warehouse::where('is_central', true)->first() ?? Warehouse::first());

            // Build map of item receipts if specific payload provided
            $itemReceiptsMap = [];
            if ($itemsPayload !== null) {
                foreach ($itemsPayload as $entry) {
                    $itemReceiptsMap[(int) $entry['purchase_item_id']] = (int) $entry['received_qty'];
                }
            }

            // Ascending sort by product_id to ensure deadlock-free lock acquisition order
            $sortedItems = $purchase->items->sortBy('product_id');
            $totalBatchReceivedAmount = 0.0;
            $itemsReceivedCount = 0;

            foreach ($sortedItems as $item) {
                $remainingPendingQty = max(0, $item->qty - $item->received_qty);

                if ($itemsPayload !== null) {
                    $qtyThisBatch = $itemReceiptsMap[$item->id] ?? 0;
                } else {
                    $qtyThisBatch = $remainingPendingQty;
                }

                if ($qtyThisBatch <= 0) {
                    continue;
                }

                if ($qtyThisBatch > $remainingPendingQty) {
                    throw new DomainException("Cannot receive {$qtyThisBatch} units for item #{$item->id}. Remaining pending quantity is only {$remainingPendingQty} (Ordered: {$item->qty}, Already Received: {$item->received_qty}).");
                }

                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();
                $receivedUnitPrice = (float) $item->unit_price;

                $currentStock = max(0, (int) $product->stock);
                $currentCost = (float) $product->cost_price;
                $totalStock = $currentStock + $qtyThisBatch;

                // Weighted Moving Average Cost Formula
                $newMovingAverageCost = $totalStock > 0
                    ? round((($currentStock * $currentCost) + ($qtyThisBatch * $receivedUnitPrice)) / $totalStock, 2)
                    : $receivedUnitPrice;

                // 1. Update Product Master
                $product->update([
                    'cost_price' => $newMovingAverageCost,
                    'stock' => $totalStock,
                ]);

                // 2. Update Physical Warehouse Location Stock
                if ($targetWarehouse) {
                    $whStock = WarehouseStock::where('warehouse_id', $targetWarehouse->id)
                        ->where('product_id', $product->id)
                        ->whereNull('product_option_id')
                        ->lockForUpdate()
                        ->first();

                    if (! $whStock) {
                        WarehouseStock::create([
                            'warehouse_id' => $targetWarehouse->id,
                            'product_id' => $product->id,
                            'product_option_id' => null,
                            'stock_qty' => $qtyThisBatch,
                        ]);
                    } else {
                        $whStock->increment('stock_qty', $qtyThisBatch);
                    }
                }

                // 3. Increment Line Item received_qty
                $item->increment('received_qty', $qtyThisBatch);

                $batchCost = round($qtyThisBatch * $receivedUnitPrice, 2);
                $totalBatchReceivedAmount += $batchCost;
                $itemsReceivedCount++;
            }

            if ($itemsReceivedCount === 0) {
                throw new DomainException("No valid quantity to receive for Purchase Order #{$purchase->po_no}.");
            }

            // 4. Update Supplier Payable Debt with row locking for the actual received batch amount
            if ($totalBatchReceivedAmount > 0 && $purchase->supplier_id) {
                $supplier = Supplier::where('id', $purchase->supplier_id)
                    ->lockForUpdate()
                    ->first();

                if ($supplier) {
                    $supplier->increment('outstanding_balance', $totalBatchReceivedAmount);
                }
            }

            // 5. Evaluate overall PO Status: Fully Received vs Partially Received
            $purchase->load('items');
            $allFullyReceived = $purchase->items->every(fn ($i) => $i->received_qty >= $i->qty);
            $newStatus = $allFullyReceived ? 'Received' : 'Partially Received';

            $purchase->update(['status' => $newStatus]);

            // 6. Immutable Audit Log
            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'purchases',
                'action' => 'receive_purchase',
                'severity' => 'info',
                'resource_type' => 'purchases',
                'resource_id' => (string) $purchase->id,
                'description' => "Goods Receipt Note (GRN) for PO #{$purchase->po_no}. Status: '{$newStatus}'. Batch amount: {$totalBatchReceivedAmount} Ks allocated to Warehouse #{$targetWarehouse?->id}.",
                'diffs' => [
                    'po_no' => $purchase->po_no,
                    'status' => $newStatus,
                    'batch_amount' => $totalBatchReceivedAmount,
                    'items_received' => $itemsReceivedCount,
                ],
                'created_at' => now(),
            ]);

            return $purchase->fresh(['items.product', 'supplier']);
        });
    }
}

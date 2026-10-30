<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReceivePurchaseAction
{
    /**
     * Receive goods from a Purchase Order (Goods Receipt Note - GRN),
     * automatically recalculating Moving Average Cost, updating physical warehouse
     * stock and master inventory with row-level locking, and crediting supplier payables.
     *
     * @throws DomainException
     */
    public function execute(int $purchaseId, ?int $warehouseId = null, ?int $operatorId = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $warehouseId, $operatorId) {
            $purchase = Purchase::with(['items.product', 'supplier'])
                ->where('id', $purchaseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status === 'Received') {
                throw new DomainException("Purchase Order #{$purchase->po_no} has already been received into inventory.");
            }

            if ($purchase->status === 'Cancelled') {
                throw new DomainException("Cannot receive cancelled Purchase Order #{$purchase->po_no}.");
            }

            $targetWarehouse = $warehouseId
                ? Warehouse::findOrFail($warehouseId)
                : (Warehouse::where('is_central', true)->first() ?? Warehouse::first());

            // Ascending sort by product_id to ensure deadlock-free lock acquisition order
            $sortedItems = $purchase->items->sortBy('product_id');

            foreach ($sortedItems as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();
                $receivedQty = (int) $item->qty;
                $receivedUnitPrice = (float) $item->unit_price;

                $currentStock = max(0, (int) $product->stock);
                $currentCost = (float) $product->cost_price;
                $totalStock = $currentStock + $receivedQty;

                // Weighted Moving Average Cost Formula
                $newMovingAverageCost = $totalStock > 0
                    ? round((($currentStock * $currentCost) + ($receivedQty * $receivedUnitPrice)) / $totalStock, 2)
                    : $receivedUnitPrice;

                // 1. Update Product Master (Persist both cost_price and incremented stock atomically)
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
                            'stock_qty' => $receivedQty,
                        ]);
                    } else {
                        $whStock->increment('stock_qty', $receivedQty);
                    }
                }
            }

            // 3. Mark Purchase Order as Received
            $purchase->update(['status' => 'Received']);

            // 4. Update Supplier Payable Debt with row locking
            if ($purchase->supplier_id) {
                $supplier = \App\Domains\Purchasing\Models\Supplier::where('id', $purchase->supplier_id)
                    ->lockForUpdate()
                    ->first();

                if ($supplier) {
                    $supplier->increment('outstanding_balance', (float) $purchase->total_amount);
                }
            }

            // 5. Immutable Audit Log
            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'purchases',
                'action' => 'receive_purchase',
                'severity' => 'info',
                'resource_type' => 'purchases',
                'resource_id' => $purchase->id,
                'description' => "Goods received for PO #{$purchase->po_no}. Total amount: {$purchase->total_amount} Ks allocated to Warehouse #{$targetWarehouse?->id}.",
                'diffs' => [
                    'po_no' => $purchase->po_no,
                    'total_amount' => $purchase->total_amount,
                    'items_count' => $purchase->items->count(),
                ],
                'created_at' => now(),
            ]);

            return $purchase->fresh(['items.product', 'supplier']);
        });
    }
}

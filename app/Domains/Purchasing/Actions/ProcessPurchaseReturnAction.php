<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseReturn;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Services\AtomicSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProcessPurchaseReturnAction
{
    public function __construct(
        protected AtomicSequenceService $sequenceService
    ) {}

    /**
     * Return damaged, defective, or incorrect goods to supplier (RTV).
     * Atomically decrements inventory, debits supplier payable debt, and records audit trail.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $operatorId
     * @return PurchaseReturn
     * @throws DomainException
     */
    public function execute(array $data, ?int $operatorId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $purchase = Purchase::with(['items', 'supplier'])
                ->where('id', $data['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status !== 'Received') {
                throw new DomainException("Cannot return goods for Purchase #{$purchase->po_no} because status is '{$purchase->status}'. Goods must be in 'Received' state.");
            }

            $productId = (int) $data['product_id'];
            $qtyToReturn = (int) $data['qty'];

            $purchaseItem = $purchase->items()->where('product_id', $productId)->first();
            if (! $purchaseItem) {
                throw new DomainException("Product #{$productId} is not part of Purchase #{$purchase->po_no}.");
            }

            if ($qtyToReturn > $purchaseItem->qty) {
                throw new DomainException("Cannot return {$qtyToReturn} units. Purchase #{$purchase->po_no} only contains {$purchaseItem->qty} units of this item.");
            }

            // Deduct product inventory with pessimistic locking
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();
            if ($product->stock < $qtyToReturn) {
                throw new DomainException("Cannot process return: current catalog stock ({$product->stock}) is lower than return quantity ({$qtyToReturn}).");
            }

            $product->decrement('stock', $qtyToReturn);

            $warehouseId = $data['warehouse_id'] ?? null;
            if ($warehouseId) {
                $whStock = WarehouseStock::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($whStock) {
                    $whStock->decrement('stock_qty', min($whStock->stock_qty, $qtyToReturn));
                }
            }

            // Calculate return financial amount
            $unitCost = (float) ($purchaseItem->unit_price ?? $purchaseItem->unit_cost ?? 0);
            $returnAmount = isset($data['amount']) && (float) $data['amount'] > 0
                ? (float) $data['amount']
                : round($unitCost * $qtyToReturn, 2);

            // Debit supplier outstanding balance
            $supplier = Supplier::where('id', $purchase->supplier_id)->lockForUpdate()->first();
            if ($supplier && isset($supplier->outstanding_balance)) {
                $supplier->decrement('outstanding_balance', min((float) $supplier->outstanding_balance, $returnAmount));
            }

            // Generate atomic sequential return number
            $returnNo = $this->sequenceService->getNextSequence('PRTN');

            $purchaseReturn = PurchaseReturn::create([
                'return_no' => $returnNo,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'product_id' => $productId,
                'qty' => $qtyToReturn,
                'amount' => $returnAmount,
                'reason' => $data['reason'],
                'status' => 'Claimed',
            ]);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'purchases',
                'action' => 'purchase_returned',
                'severity' => 'warning',
                'resource_type' => 'purchase_returns',
                'resource_id' => (string) $purchaseReturn->id,
                'description' => "RTV Debit Note #{$returnNo}: Returned {$qtyToReturn}x {$product->name} to {$supplier?->name} for {$returnAmount} Ks. Reason: {$data['reason']}",
                'diffs' => [
                    'return_no' => $returnNo,
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'qty' => $qtyToReturn,
                    'amount' => $returnAmount,
                ],
                'created_at' => now(),
            ]);

            return $purchaseReturn->load(['supplier', 'purchase', 'product']);
        });
    }
}

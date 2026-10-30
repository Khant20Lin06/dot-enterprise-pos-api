<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Purchasing\Models\PurchaseReturn;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class TransitionPurchaseReturnStatusAction
{
    /**
     * Transition a Purchase Return (RTV) to a new status with accounting and stock adjustments.
     *
     * @param  PurchaseReturn  $return
     * @param  string  $newStatus
     * @param  array<string, mixed>  $payload
     * @param  int|null  $operatorId
     * @return PurchaseReturn
     * @throws DomainException
     */
    public function execute(PurchaseReturn $return, string $newStatus, array $payload = [], ?int $operatorId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $newStatus, $operatorId) {
            $lockedReturn = PurchaseReturn::where('id', $return->id)->lockForUpdate()->firstOrFail();
            $currentStatus = $lockedReturn->status;

            if (in_array($currentStatus, ['Credited', 'Replaced', 'Cancelled'])) {
                throw new DomainException("Cannot transition Purchase Return #{$lockedReturn->return_no} from terminal status '{$currentStatus}'.");
            }

            if ($newStatus === 'Cancelled') {
                if ($currentStatus !== 'Claimed') {
                    throw new DomainException("Cannot cancel Purchase Return #{$lockedReturn->return_no} once dispatched. Current status: '{$currentStatus}'.");
                }

                // Reverse RTV: Restock product inventory and restore supplier payable balance
                $product = Product::where('id', $lockedReturn->product_id)->lockForUpdate()->firstOrFail();
                $product->increment('stock', $lockedReturn->qty);

                $supplier = Supplier::where('id', $lockedReturn->supplier_id)->lockForUpdate()->first();
                if ($supplier) {
                    $supplier->increment('outstanding_balance', $lockedReturn->amount);
                }

                $lockedReturn->update(['status' => 'Cancelled']);
            } elseif ($newStatus === 'Replaced') {
                // Vendor replaced defective goods: Restock catalog inventory and restore supplier balance
                $product = Product::where('id', $lockedReturn->product_id)->lockForUpdate()->firstOrFail();
                $product->increment('stock', $lockedReturn->qty);

                $supplier = Supplier::where('id', $lockedReturn->supplier_id)->lockForUpdate()->first();
                if ($supplier) {
                    $supplier->increment('outstanding_balance', $lockedReturn->amount);
                }

                $lockedReturn->update(['status' => 'Replaced']);
            } elseif ($newStatus === 'Dispatched') {
                if ($currentStatus !== 'Claimed') {
                    throw new DomainException("Cannot mark return as Dispatched from status '{$currentStatus}'.");
                }
                $lockedReturn->update(['status' => 'Dispatched']);
            } elseif ($newStatus === 'Credited') {
                // Final confirmation of vendor debit note / credit memo
                $lockedReturn->update(['status' => 'Credited']);
            } else {
                throw new DomainException("Invalid target status '{$newStatus}' for Purchase Return.");
            }

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'purchases',
                'action' => 'purchase_return_status_changed',
                'severity' => in_array($newStatus, ['Cancelled', 'Replaced']) ? 'warning' : 'info',
                'resource_type' => 'purchase_returns',
                'resource_id' => (string) $lockedReturn->id,
                'description' => "Purchase Return #{$lockedReturn->return_no} status transitioned from '{$currentStatus}' to '{$newStatus}'.",
                'diffs' => [
                    'return_no' => $lockedReturn->return_no,
                    'old_status' => $currentStatus,
                    'new_status' => $newStatus,
                ],
                'created_at' => now(),
            ]);

            return $lockedReturn->load(['supplier', 'purchase', 'product']);
        });
    }
}

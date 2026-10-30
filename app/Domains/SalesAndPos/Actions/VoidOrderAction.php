<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VoidOrderAction
{
    /**
     * Officially void an erroneous POS sales order within the active shift.
     * Restores deducted inventory with pessimistic row locking, reverses credit debts and loyalty points,
     * and logs an immutable audit trail.
     *
     * @param  int  $orderId
     * @param  string  $reason
     * @param  int|null  $operatorId
     * @param  string|null  $supervisorPin
     * @return SalesOrder
     * @throws DomainException
     */
    public function execute(
        int $orderId,
        string $reason,
        ?int $operatorId = null,
        ?string $supervisorPin = null
    ): SalesOrder {
        if (empty(trim($reason))) {
            throw new DomainException('A valid reason is strictly required to void a completed POS order.');
        }

        return DB::transaction(function () use ($orderId, $reason, $operatorId, $supervisorPin) {
            // 1. Lock sales order row
            $order = SalesOrder::with(['items', 'customer', 'posSession', 'paymentSplits'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validate current order state
            if ($order->status === 'Voided') {
                throw new DomainException("Order #{$order->order_no} has already been voided.");
            }

            if ($order->status === 'Returned') {
                throw new DomainException("Cannot void Order #{$order->order_no} because it has already been refunded via Sales Return.");
            }

            // 3. Strict Shift Isolation: Cannot void order from a closed session
            if ($order->posSession && $order->posSession->status === 'closed') {
                throw new DomainException("Cannot void Order #{$order->order_no} from a closed shift session. Please process an official Sales Return instead for accounting compliance.");
            }

            // 4. Validate Supervisor Authorization
            $authorizingSupervisorId = null;

            if (! empty($supervisorPin)) {
                $candidates = StaffUser::with('role')->where('status', 'Active')->get();
                foreach ($candidates as $staff) {
                    if ($staff->pin_hash && Hash::check($supervisorPin, $staff->pin_hash)) {
                        $roleCode = strtoupper($staff->role?->code ?? '');
                        if ($roleCode === 'ADMIN' || (bool) ($staff->role?->is_system ?? false) || $staff->hasPermission('pos', 'voidInvoice')) {
                            $authorizingSupervisorId = $staff->id;
                            break;
                        }
                    }
                }

                if (! $authorizingSupervisorId) {
                    throw new DomainException('Invalid supervisor PIN or supervisor lacks required voidInvoice permission.');
                }
            } elseif ($operatorId) {
                $operator = StaffUser::with('role')->find($operatorId);
                $roleCode = strtoupper($operator?->role?->code ?? '');
                if ($operator && ($roleCode === 'ADMIN' || (bool) ($operator->role?->is_system ?? false) || $operator->hasPermission('pos', 'voidInvoice'))) {
                    $authorizingSupervisorId = $operator->id;
                } else {
                    throw new DomainException('Unauthorized: Voiding an order requires supervisor or manager authorization.');
                }
            } else {
                throw new DomainException('Supervisor PIN or authorized operator ID is required to void this order.');
            }

            // 5. Atomic Inventory Restocking
            $warehouseId = $order->posSession?->warehouse_id;

            foreach ($order->items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if ($product && ! $product->is_labor) {
                    $product->increment('stock', $item->qty);

                    if ($warehouseId) {
                        WarehouseStock::where('warehouse_id', $warehouseId)
                            ->where('product_id', $item->product_id)
                            ->lockForUpdate()
                            ->increment('stock_qty', $item->qty);
                    }
                }
            }

            // 6. Reverse Financial & Customer Balances
            if ($order->customer_id) {
                $customer = Customer::where('id', $order->customer_id)->lockForUpdate()->first();

                if ($customer) {
                    // Reverse unpaid credit debt recorded on this order
                    if ((float) $order->due_amount > 0) {
                        $customer->decrement('due_amount', min((float) $order->due_amount, (float) $customer->due_amount));
                    }

                    // Reverse earned loyalty points
                    if ((int) $order->points_earned > 0) {
                        $customer->decrement('loyalty_points', min((int) $order->points_earned, (int) $customer->loyalty_points));
                    }

                    // Refund spent loyalty points
                    if ((int) $order->points_redeemed > 0) {
                        $customer->increment('loyalty_points', (int) $order->points_redeemed);
                    }
                }
            }

            // 7. Update Order Status
            $order->update([
                'status' => 'Voided',
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $authorizingSupervisorId,
            ]);

            // 8. Immutable Audit Log
            AuditLog::create([
                'staff_id' => $authorizingSupervisorId,
                'module' => 'pos',
                'action' => 'order_voided',
                'severity' => 'warning',
                'resource_type' => 'sales_orders',
                'resource_id' => (string) $order->id,
                'description' => "Order #{$order->order_no} voided by Staff #{$authorizingSupervisorId}. Reason: {$reason}",
                'diffs' => [
                    'order_no' => $order->order_no,
                    'total_amount' => $order->total_amount,
                    'reason' => $reason,
                    'items_count' => $order->items->count(),
                ],
                'created_at' => now(),
            ]);

            return $order->fresh(['items', 'customer', 'posSession', 'paymentSplits', 'voidedBy']);
        });
    }
}

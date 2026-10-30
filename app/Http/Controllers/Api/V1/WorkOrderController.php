<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Events\WorkOrderStatusChangedEvent;
use App\Domains\Workshop\Models\WorkOrder;
use App\Domains\Workshop\Models\WorkOrderItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateWorkOrderRequest;
use App\Http\Requests\V1\UpdateWorkOrderRequest;
use App\Http\Resources\V1\SalesOrderResource;
use App\Http\Resources\V1\WorkOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    /**
     * List automotive workshop job cards with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WorkOrder::with(['customer', 'vehicle', 'serviceAdvisor', 'primaryMechanic', 'items.product']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('mechanic_id')) {
            $query->where('primary_mechanic_id', $request->query('mechanic_id'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $workOrders = $query->latest('id')->paginate($perPage);

        return WorkOrderResource::collection($workOrders);
    }

    /**
     * Create automotive Job Card intake with vehicle diagnostics and line items.
     */
    public function store(CreateWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $advisorId = $validated['service_advisor_id'] ?? ($request->user()?->id ?? 1);
        $jobCardNo = app(\App\Services\AtomicSequenceService::class)->getNextSequence('JC');

        // VULN-05 Hardening: Validate Vehicle Ownership to prevent IDOR / cross-customer billing
        $vehicle = \App\Domains\Workshop\Models\Vehicle::where('id', $validated['vehicle_id'])
            ->where('customer_id', $validated['customer_id'])
            ->first();

        if (! $vehicle) {
            return response()->json([
                'success' => false,
                'message' => "Relational Error: Vehicle ID {$validated['vehicle_id']} does not belong to Customer ID {$validated['customer_id']}.",
            ], 422);
        }

        $workOrder = DB::transaction(function () use ($validated, $advisorId, $jobCardNo) {
            $partsTotal = 0.00;
            $laborTotal = 0.00;

            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    $itemTotal = (float) $item['unit_price'] * (int) $item['qty'];
                    if (($item['item_type'] ?? 'part') === 'labor') {
                        $laborTotal += $itemTotal;
                    } else {
                        $partsTotal += $itemTotal;
                    }
                }
            }

            $order = WorkOrder::create([
                'job_card_no' => $jobCardNo,
                'customer_id' => $validated['customer_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'assigned_mechanic_id' => $validated['primary_mechanic_id'] ?? null,
                'status' => 'Intake / Estimate',
                'mileage_in' => $validated['mileage_in'] ?? 0,
                'fuel_level' => $validated['fuel_level'] ?? null,
                'complaint' => $validated['complaint'],
                'parts_total' => $partsTotal,
                'labor_fee' => $laborTotal,
                'total_amount' => $partsTotal + $laborTotal,
            ]);

            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    WorkOrderItem::create([
                        'work_order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'item_type' => $item['item_type'] ?? 'part',
                        'name' => $item['name'] ?? 'Workshop Item',
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => (float) $item['unit_price'] * (int) $item['qty'],
                        'is_approved' => $item['is_approved'] ?? true,
                    ]);
                }
            }

            return $order->load(['customer', 'vehicle', 'serviceAdvisor', 'primaryMechanic', 'items.product']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Job Card created successfully.',
            'data' => new WorkOrderResource($workOrder),
        ], 201);
    }

    /**
     * Show single Job Card details.
     */
    public function show(int $id): JsonResponse
    {
        $workOrder = WorkOrder::with(['customer', 'vehicle', 'serviceAdvisor', 'primaryMechanic', 'items.product'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Update automotive Job Card complaint, details, or line items.
     */
    public function update(UpdateWorkOrderRequest $request, int $id): JsonResponse
    {
        $workOrder = WorkOrder::with('items')->findOrFail($id);

        if (in_array($workOrder->status, ['Completed / Invoiced', 'Cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot update Job Card #{$workOrder->job_card_no} with status '{$workOrder->status}'.",
            ], 422);
        }

        $validated = $request->validated();

        $updated = DB::transaction(function () use ($workOrder, $validated) {
            if (isset($validated['items'])) {
                // Delete existing items and recreate
                $workOrder->items()->delete();

                $partsTotal = 0.00;
                $laborTotal = 0.00;

                foreach ($validated['items'] as $item) {
                    $itemTotal = (float) $item['unit_price'] * (int) $item['qty'];
                    if (($item['item_type'] ?? 'part') === 'labor') {
                        $laborTotal += $itemTotal;
                    } else {
                        $partsTotal += $itemTotal;
                    }

                    WorkOrderItem::create([
                        'work_order_id' => $workOrder->id,
                        'product_id' => $item['product_id'],
                        'item_type' => $item['item_type'] ?? 'part',
                        'name' => $item['name'] ?? 'Workshop Item',
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $itemTotal,
                        'is_approved' => $item['is_approved'] ?? true,
                    ]);
                }

                $workOrder->parts_total = $partsTotal;
                $workOrder->labor_fee = $laborTotal;
                $workOrder->total_amount = $partsTotal + $laborTotal;
            }

            $fillable = array_diff_key($validated, ['items' => true]);
            $workOrder->fill($fillable);
            $workOrder->save();

            return $workOrder->fresh(['customer', 'vehicle', 'serviceAdvisor', 'primaryMechanic', 'items.product']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Job Card updated successfully.',
            'data' => new WorkOrderResource($updated),
        ]);
    }

    /**
     * Transition Job Card status and broadcast WebSocket update.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:Intake / Estimate,Under Inspection,In Progress,Waiting for Parts,QC & Ready,Cancelled'],
        ]);

        $workOrder = WorkOrder::findOrFail($id);

        if ($workOrder->status === 'Completed / Invoiced') {
            return response()->json([
                'success' => false,
                'message' => "Cannot change status of invoiced Job Card #{$workOrder->job_card_no}.",
            ], 422);
        }

        $prevStatus = $workOrder->status;
        $workOrder->update(['status' => $request->status]);

        event(new WorkOrderStatusChangedEvent($workOrder->fresh(['vehicle', 'customer']), $prevStatus));

        return response()->json([
            'success' => true,
            'message' => "Job Card status transitioned to '{$request->status}'.",
            'data' => new WorkOrderResource($workOrder->fresh(['customer', 'vehicle', 'serviceAdvisor', 'primaryMechanic', 'items.product'])),
        ]);
    }

    /**
     * Cancel an active Job Card.
     */
    public function cancel(int $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        if ($workOrder->status === 'Completed / Invoiced') {
            return response()->json([
                'success' => false,
                'message' => "Cannot cancel an invoiced Job Card #{$workOrder->job_card_no}.",
            ], 422);
        }

        $prevStatus = $workOrder->status;
        $workOrder->update(['status' => 'Cancelled']);

        event(new WorkOrderStatusChangedEvent($workOrder->fresh(['vehicle', 'customer']), $prevStatus));

        return response()->json([
            'success' => true,
            'message' => "Job Card #{$workOrder->job_card_no} has been cancelled.",
        ]);
    }

    /**
     * Convert automotive Job Card into official POS invoice, deducting part inventory & logging commissions.
     * Supports both single payment and multi-tender split payments.
     */
    public function convertToInvoice(int $id, ConvertWorkOrderToInvoiceAction $action, Request $request): JsonResponse
    {
        $cashierId = $request->user()?->id ?? 1;
        $payments = $request->input('payments') ?? $request->input('payment_splits') ?? $request->input('payment_method', 'Cash');

        $result = $action->execute(
            workOrderId: $id,
            paymentMethod: $payments,
            cashierId: $cashierId,
            paymentRef: $request->input('payment_ref'),
            warehouseId: $request->input('warehouse_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Job Card successfully converted to official POS invoice.',
            'data' => [
                'salesOrder' => new SalesOrderResource($result['sales_order']),
                'workOrder' => new WorkOrderResource($result['work_order']),
                'commission' => $result['commission'],
            ],
        ]);
    }
}

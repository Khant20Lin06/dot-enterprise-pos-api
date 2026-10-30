<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Purchasing\Actions\ReceivePurchaseAction;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreatePurchaseRequest;
use App\Http\Requests\V1\UpdatePurchaseRequest;
use App\Http\Resources\V1\PurchaseResource;
use App\Services\AtomicSequenceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /**
     * List purchase orders with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Purchase::with(['supplier', 'items.product']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('po_no', 'like', "%{$search}%");
        }

        $purchases = $query->latest('id')->paginate(20);

        return PurchaseResource::collection($purchases);
    }

    /**
     * Create a new Purchase Order (PO).
     */
    public function store(
        CreatePurchaseRequest $request,
        AtomicSequenceService $sequenceService,
        ReceivePurchaseAction $receiveAction
    ): JsonResponse {
        $validated = $request->validated();
        $operatorId = $request->user()?->id;

        $purchase = DB::transaction(function () use ($validated, $sequenceService) {
            $poNo = $sequenceService->getNextSequence('PO');
            $totalAmount = 0.00;

            foreach ($validated['items'] as $item) {
                $totalAmount += round((int) $item['qty'] * (float) $item['unit_price'], 2);
            }

            $purchase = Purchase::create([
                'po_no' => $poNo,
                'supplier_id' => $validated['supplier_id'],
                'total_amount' => $totalAmount,
                'status' => 'Draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $qty = (int) $item['qty'];
                $unitPrice = (float) $item['unit_price'];

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => round($qty * $unitPrice, 2),
                ]);
            }

            return $purchase;
        });

        // Auto-receive if requested
        if ($request->boolean('receive_immediately')) {
            $purchase = $receiveAction->execute(
                purchaseId: $purchase->id,
                warehouseId: $validated['warehouse_id'] ?? null,
                operatorId: $operatorId
            );
        } else {
            $purchase->load(['supplier', 'items.product']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase Order created successfully.',
            'data' => new PurchaseResource($purchase),
        ], 201);
    }

    /**
     * Show single purchase details.
     */
    public function show(int $id): JsonResponse
    {
        $purchase = Purchase::with(['supplier', 'items.product'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PurchaseResource($purchase),
        ]);
    }

    /**
     * Update an existing Draft or Pending Purchase Order.
     */
    public function update(UpdatePurchaseRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $purchase = DB::transaction(function () use ($validated, $id) {
                $purchase = Purchase::where('id', $id)->lockForUpdate()->firstOrFail();

                if (! in_array($purchase->status, ['Draft', 'Pending'])) {
                    throw new DomainException("Cannot edit Purchase Order #{$purchase->po_no} because it is in '{$purchase->status}' status. Only Draft or Pending POs can be edited.");
                }

                if (isset($validated['supplier_id'])) {
                    $purchase->supplier_id = $validated['supplier_id'];
                }

                if (array_key_exists('notes', $validated)) {
                    $purchase->notes = $validated['notes'];
                }

                if (isset($validated['items'])) {
                    $purchase->items()->delete();

                    $totalAmount = 0.00;
                    foreach ($validated['items'] as $item) {
                        $qty = (int) $item['qty'];
                        $unitPrice = (float) $item['unit_price'];
                        $subtotal = round($qty * $unitPrice, 2);
                        $totalAmount += $subtotal;

                        PurchaseItem::create([
                            'purchase_id' => $purchase->id,
                            'product_id' => $item['product_id'],
                            'qty' => $qty,
                            'unit_price' => $unitPrice,
                            'subtotal' => $subtotal,
                        ]);
                    }

                    $purchase->total_amount = $totalAmount;
                }

                $purchase->save();

                return $purchase;
            });

            $purchase->load(['supplier', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => "Purchase Order #{$purchase->po_no} updated successfully.",
                'data' => new PurchaseResource($purchase),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Receive goods from purchase order into inventory (GRN).
     */
    public function receive(int $id, Request $request, ReceivePurchaseAction $action): JsonResponse
    {
        $warehouseId = $request->input('warehouse_id');
        $operatorId = $request->user()?->id;

        try {
            $purchase = $action->execute($id, $warehouseId, $operatorId);

            return response()->json([
                'success' => true,
                'message' => "Goods for PO #{$purchase->po_no} received successfully into inventory.",
                'data' => new PurchaseResource($purchase),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel draft purchase order.
     */
    public function cancel(int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);

        if ($purchase->status === 'Received') {
            return response()->json([
                'success' => false,
                'message' => "Cannot cancel PO #{$purchase->po_no} because items have already been received into physical inventory.",
            ], 422);
        }

        $purchase->update(['status' => 'Cancelled']);

        return response()->json([
            'success' => true,
            'message' => "Purchase Order #{$purchase->po_no} cancelled successfully.",
        ]);
    }
}

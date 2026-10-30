<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Purchasing\Actions\ProcessPurchaseReturnAction;
use App\Domains\Purchasing\Actions\TransitionPurchaseReturnStatusAction;
use App\Domains\Purchasing\Models\PurchaseReturn;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreatePurchaseReturnRequest;
use App\Http\Requests\V1\UpdatePurchaseReturnStatusRequest;
use App\Http\Resources\V1\PurchaseReturnResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseReturnController extends Controller
{
    /**
     * List all Purchase Returns (Return to Vendor - RTV).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseReturn::with(['purchase', 'supplier', 'product']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('purchase_id')) {
            $query->where('purchase_id', $request->query('purchase_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $returns = $query->latest('id')->paginate($perPage);

        return PurchaseReturnResource::collection($returns);
    }

    /**
     * Return goods to supplier, reduce stock, and debit payable balance.
     */
    public function store(CreatePurchaseReturnRequest $request, ProcessPurchaseReturnAction $action): JsonResponse
    {
        try {
            $purchaseReturn = $action->execute($request->validated(), $request->user()?->id);

            return response()->json([
                'success' => true,
                'message' => "Debit Note #{$purchaseReturn->return_no} issued. {$purchaseReturn->qty} units returned to vendor.",
                'data' => new PurchaseReturnResource($purchaseReturn),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show single purchase return details.
     */
    public function show(int $id): JsonResponse
    {
        $purchaseReturn = PurchaseReturn::with(['purchase', 'supplier', 'product'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PurchaseReturnResource($purchaseReturn),
        ]);
    }

    /**
     * Transition purchase return status (Claimed -> Dispatched -> Credited / Replaced / Cancelled).
     */
    public function updateStatus(
        int $id,
        UpdatePurchaseReturnStatusRequest $request,
        TransitionPurchaseReturnStatusAction $action
    ): JsonResponse {
        $purchaseReturn = PurchaseReturn::findOrFail($id);

        try {
            $updated = $action->execute(
                $purchaseReturn,
                $request->validated('status'),
                $request->validated(),
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => "Purchase Return #{$updated->return_no} status changed to '{$updated->status}'.",
                'data' => new PurchaseReturnResource($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

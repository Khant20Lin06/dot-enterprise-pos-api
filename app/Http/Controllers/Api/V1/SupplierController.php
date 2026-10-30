<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Purchasing\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateSupplierRequest;
use App\Http\Requests\V1\UpdateSupplierRequest;
use App\Http\Resources\V1\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    /**
     * List suppliers with search and balance filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Supplier::withCount(['products', 'purchases']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('has_balance')) {
            $query->where('outstanding_balance', '>', 0);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $suppliers = $query->orderBy('name')->paginate(20);

        return SupplierResource::collection($suppliers);
    }

    /**
     * Create a new supplier.
     */
    public function store(CreateSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Supplier registered successfully.',
            'data' => new SupplierResource($supplier),
        ], 201);
    }

    /**
     * Show single supplier details.
     */
    public function show(int $id): JsonResponse
    {
        $supplier = Supplier::withCount(['products', 'purchases'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Update supplier profile.
     */
    public function update(UpdateSupplierRequest $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated successfully.',
            'data' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Delete supplier (guarded against purchase orders and active products).
     */
    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        if ($supplier->purchases()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete supplier '{$supplier->name}'. Historical purchase orders exist.",
            ], 422);
        }

        if ($supplier->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete supplier '{$supplier->name}'. There are active products assigned to this supplier.",
            ], 422);
        }

        if ($supplier->outsourcedParts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete supplier '{$supplier->name}'. Outsourced workshop machining records exist.",
            ], 422);
        }

        if ($supplier->purchaseReturns()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete supplier '{$supplier->name}'. Return-to-vendor (RTV) debit notes exist.",
            ], 422);
        }

        if ((float) $supplier->outstanding_balance > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete supplier '{$supplier->name}' with outstanding accounts payable balance ({$supplier->outstanding_balance} Ks). Settle balance before deletion.",
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supplier deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted supplier (Enterprise Asset Recovery).
     */
    public function restore(int $id): JsonResponse
    {
        $supplier = Supplier::onlyTrashed()->findOrFail($id);
        $supplier->restore();

        return response()->json([
            'success' => true,
            'message' => "Supplier '{$supplier->name}' restored successfully.",
            'data' => new SupplierResource($supplier),
        ]);
    }
}

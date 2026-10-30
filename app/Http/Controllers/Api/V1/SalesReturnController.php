<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SalesAndPos\Actions\ProcessSaleReturnAction;
use App\Domains\SalesAndPos\Models\SalesReturn;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateSalesReturnRequest;
use App\Http\Resources\V1\SalesReturnResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesReturnController extends Controller
{
    /**
     * Process an itemized sales return with inventory restocking, points reversal, and credit adjustment.
     */
    public function store(CreateSalesReturnRequest $request, ProcessSaleReturnAction $action): JsonResponse
    {
        $cashierId = $request->user()?->id ?? 1;

        $salesReturn = $action->execute($request->validated(), $cashierId);

        return response()->json([
            'success' => true,
            'message' => "Sales Return #{$salesReturn->return_no} processed successfully.",
            'data' => new SalesReturnResource($salesReturn),
        ], 201);
    }

    /**
     * List historical sales returns with filtering options.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SalesReturn::with(['salesOrder', 'customer', 'cashier', 'items.product']);

        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', $request->query('sales_order_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('return_no', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $returns = $query->latest('id')->paginate($perPage);

        return SalesReturnResource::collection($returns);
    }

    /**
     * Show details of a specific sales return receipt.
     */
    public function show(int $id): JsonResponse
    {
        $salesReturn = SalesReturn::with(['salesOrder', 'customer', 'cashier', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new SalesReturnResource($salesReturn),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\CreateWarehouseAction;
use App\Domains\Inventory\Actions\UpdateWarehouseAction;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateWarehouseRequest;
use App\Http\Requests\V1\UpdateWarehouseRequest;
use App\Http\Resources\V1\WarehouseResource;
use App\Http\Resources\V1\WarehouseStockResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseController extends Controller
{
    /**
     * List all warehouses and physical branch locations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Warehouse::query();

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $warehouses = $query->with('stocks')->orderBy('id')->get();

        return WarehouseResource::collection($warehouses);
    }

    /**
     * Create a new branch or warehouse location.
     */
    public function store(CreateWarehouseRequest $request, CreateWarehouseAction $action): JsonResponse
    {
        $warehouse = $action->execute($request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Warehouse '{$warehouse->name}' created successfully.",
            'data' => new WarehouseResource($warehouse),
        ], 201);
    }

    /**
     * Show details of a specific warehouse.
     */
    public function show(int $id): JsonResponse
    {
        $warehouse = Warehouse::with('stocks')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new WarehouseResource($warehouse),
        ]);
    }

    /**
     * Update warehouse profile, address or active flag.
     */
    public function update(UpdateWarehouseRequest $request, int $id, UpdateWarehouseAction $action): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);
        $updated = $action->execute($warehouse, $request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Warehouse '{$updated->name}' updated successfully.",
            'data' => new WarehouseResource($updated),
        ]);
    }

    /**
     * Query stock levels and bin locations within a specific warehouse.
     */
    public function stocks(Request $request, int $id): AnonymousResourceCollection
    {
        $warehouse = Warehouse::findOrFail($id);

        $query = WarehouseStock::with(['product', 'warehouse'])
            ->where('warehouse_id', $warehouse->id);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_qty', '<=', 'min_alert_qty');
        }

        $perPage = min((int) $request->query('per_page', 50), 100);
        $stocks = $query->paginate($perPage);

        return WarehouseStockResource::collection($stocks);
    }
}

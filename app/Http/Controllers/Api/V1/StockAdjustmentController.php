<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateStockAdjustmentRequest;
use App\Http\Resources\V1\StockAdjustmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    /**
     * List stock adjustment records with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockAdjustment::with(['product', 'staff']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $adjustments = $query->latest('id')->paginate(20);

        return StockAdjustmentResource::collection($adjustments);
    }

    /**
     * Create physical inventory stock adjustment with row locking and audit logging.
     */
    public function store(CreateStockAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $staffId = $request->user()?->id;

        try {
            $adjustment = DB::transaction(function () use ($validated, $staffId) {
                $product = Product::where('id', $validated['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $prevStock = (int) $product->stock;
                $adjustedQty = (int) $validated['adjusted_qty'];
                $newStock = $prevStock + $adjustedQty;

                if ($newStock < 0) {
                    throw new \DomainException("Negative stock violation: Product '{$product->name}' currently has {$prevStock} units in stock. Cannot adjust by {$adjustedQty} units.");
                }

                // Update Master Product Stock
                $product->update(['stock' => $newStock]);

                // Reconcile Target Warehouse Stock
                $warehouseId = $validated['warehouse_id'] ?? null;
                $targetWarehouse = $warehouseId
                    ? Warehouse::find($warehouseId)
                    : (Warehouse::where('is_central', true)->first() ?? Warehouse::first());

                if ($targetWarehouse) {
                    $whStock = WarehouseStock::where('warehouse_id', $targetWarehouse->id)
                        ->where('product_id', $product->id)
                        ->whereNull('product_option_id')
                        ->lockForUpdate()
                        ->first();

                    $currentWhQty = $whStock ? (int) $whStock->stock_qty : 0;
                    $projectedWhQty = $currentWhQty + $adjustedQty;

                    if ($projectedWhQty < 0) {
                        throw new \DomainException("Negative warehouse stock violation: Warehouse '{$targetWarehouse->name}' currently has {$currentWhQty} units of '{$product->name}'. Cannot adjust by {$adjustedQty} units.");
                    }

                    if (! $whStock) {
                        WarehouseStock::create([
                            'warehouse_id' => $targetWarehouse->id,
                            'product_id' => $product->id,
                            'product_option_id' => null,
                            'stock_qty' => $projectedWhQty,
                        ]);
                    } else {
                        $whStock->update(['stock_qty' => $projectedWhQty]);
                    }
                }

                $adjustment = StockAdjustment::create([
                    'product_id' => $product->id,
                    'type' => $validated['type'],
                    'prev_stock' => $prevStock,
                    'adjusted_qty' => $adjustedQty,
                    'new_stock' => $newStock,
                    'reason' => $validated['reason'],
                    'staff_id' => $staffId,
                ]);

                return $adjustment;
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment recorded successfully.',
                'data' => new StockAdjustmentResource($adjustment->load(['product', 'staff'])),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show single stock adjustment audit voucher details.
     */
    public function show(int $id): JsonResponse
    {
        $adjustment = StockAdjustment::with(['product', 'staff'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new StockAdjustmentResource($adjustment),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\GenerateVariantMatrixAction;
use App\Domains\Inventory\Actions\ResolveBarcodeAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateProductRequest;
use App\Http\Requests\V1\GenerateVariantMatrixRequest;
use App\Http\Requests\V1\UpdateProductRequest;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ProductVariantResource;
use App\Services\AtomicSequenceService;
use App\Services\NodeWorkerBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Search and list products with SKU, Barcode, Category filter, and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::with(['category', 'unitOfMeasure', 'options']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('barcode')) {
            $query->where('barcode', $request->query('barcode'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        if ($request->boolean('is_labor')) {
            $query->where('is_labor', true);
        } elseif ($request->has('is_labor') && ! $request->boolean('is_labor')) {
            $query->where('is_labor', false);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $products = $query->latest('id')->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Show single product details with option variants.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with(['category', 'unitOfMeasure', 'options'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Create a new product with optional initial inventory allocation.
     */
    public function store(CreateProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $product = DB::transaction(function () use ($validated) {
            $initialStock = (int) ($validated['initial_stock'] ?? 0);
            $warehouseId = $validated['warehouse_id'] ?? null;

            // Generate unique barcode if omitted
            if (empty($validated['barcode'])) {
                $validated['barcode'] = 'PRD-' . strtoupper(bin2hex(random_bytes(4)));
            }

            $validated['stock'] = $initialStock;

            $product = Product::create($validated);

            // Reconcile initial physical stock in target warehouse to prevent stock drift
            if ($initialStock > 0 && ! $product->is_labor) {
                $targetWarehouse = $warehouseId
                    ? Warehouse::find($warehouseId)
                    : (Warehouse::where('is_central', true)->first() ?? Warehouse::first());

                if ($targetWarehouse) {
                    WarehouseStock::updateOrCreate(
                        [
                            'warehouse_id' => $targetWarehouse->id,
                            'product_id' => $product->id,
                            'product_option_id' => null,
                        ],
                        [
                            'stock_qty' => $initialStock,
                        ]
                    );
                }
            }

            return $product;
        });

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => new ProductResource($product->load(['category', 'unitOfMeasure'])),
        ], 201);
    }

    /**
     * Update product details and catalog pricing.
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => new ProductResource($product->fresh(['category', 'unitOfMeasure'])),
        ]);
    }

    /**
     * Delete product (guarded against sales and workshop transaction history).
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        if ($product->salesOrderItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). It is referenced in past sales order invoices. Retain record for accounting integrity.",
            ], 422);
        }

        if ($product->workOrderItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). It is referenced in past automotive workshop job cards.",
            ], 422);
        }

        if ($product->purchaseItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). Historical purchase orders exist. Retain record for procurement audit integrity.",
            ], 422);
        }

        if ($product->purchaseReturns()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). Return-to-vendor (RTV) debit notes exist.",
            ], 422);
        }

        if ($product->stockAdjustments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). Inventory stock audit vouchers exist.",
            ], 422);
        }

        if ($product->stockTransferItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}). Inter-warehouse stock transfer records exist.",
            ], 422);
        }

        if ((int) $product->stock > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete product '{$product->name}' (#{$id}) because it has physical stock ({$product->stock} units). Adjust stock to zero before deleting.",
            ], 422);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted product (Enterprise Asset Recovery).
     */
    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return response()->json([
            'success' => true,
            'message' => "Product '{$product->name}' restored successfully.",
            'data' => new ProductResource($product->load(['category', 'unitOfMeasure'])),
        ]);
    }

    /**
     * Generate high-speed barcode for product label printing via Node.js microservice.
     */
    public function barcode(int $id, NodeWorkerBridgeService $bridge): JsonResponse
    {
        $product = Product::findOrFail($id);
        $code = $product->barcode ?: $product->sku;

        $barcodeData = $bridge->generateBarcode($code, 'code128');

        return response()->json([
            'success' => true,
            'productId' => $product->id,
            'barcode' => $code,
            'image' => $barcodeData,
        ]);
    }

    /**
     * List all variants of a product.
     */
    public function variants(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $variants = $product->variants()
            ->with('attributeValues.attribute')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'productId' => $product->id,
            'hasVariants' => (bool) $product->has_variants,
            'data' => ProductVariantResource::collection($variants),
        ]);
    }

    /**
     * Generate Cartesian Product Variant Matrix for a product.
     */
    public function generateMatrix(GenerateVariantMatrixRequest $request, int $id, GenerateVariantMatrixAction $action): JsonResponse
    {
        $product = Product::findOrFail($id);
        $variants = $action->execute(
            $product,
            $request->validated('attribute_value_ids'),
            $request->safe()->only(['price', 'cost_price', 'initial_stock']),
            $request->user()?->id
        );

        return response()->json([
            'success' => true,
            'message' => count($variants) . " variants generated successfully for product '{$product->name}'.",
            'productId' => $product->id,
            'data' => ProductVariantResource::collection($variants),
        ], 201);
    }

    /**
     * Resolve scanned barcode or SKU across product variants and master products.
     */
    public function lookupBarcode(string $barcode, ResolveBarcodeAction $action): JsonResponse
    {
        $result = $action->execute($barcode);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => "Item with barcode or SKU '{$barcode}' not found.",
            ], 404);
        }

        if ($result['type'] === 'variant') {
            return response()->json([
                'success' => true,
                'type' => 'variant',
                'data' => new ProductVariantResource($result['variant']),
                'product' => new ProductResource($result['product']),
            ]);
        }

        return response()->json([
            'success' => true,
            'type' => 'product',
            'data' => new ProductResource($result['product']),
        ]);
    }
}

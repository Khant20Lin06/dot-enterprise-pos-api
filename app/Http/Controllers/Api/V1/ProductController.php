<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\GenerateVariantMatrixAction;
use App\Domains\Inventory\Actions\ResolveBarcodeAction;
use App\Domains\Inventory\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\GenerateVariantMatrixRequest;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ProductVariantResource;
use App\Services\NodeWorkerBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
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
}

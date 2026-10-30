<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\CreateProductUomConversionAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateProductUomConversionRequest;
use App\Http\Resources\V1\ProductUomConversionResource;
use Illuminate\Http\JsonResponse;

class ProductUomConversionController extends Controller
{
    /**
     * List all packaging UOM conversion rules for a product.
     */
    public function index(int $productId): JsonResponse
    {
        $product = Product::with(['uomConversions.uom', 'uom'])->findOrFail($productId);

        return response()->json([
            'success' => true,
            'baseUom' => $product->uom?->name ?? 'Piece',
            'data' => ProductUomConversionResource::collection($product->uomConversions),
        ]);
    }

    /**
     * Create a packaging UOM conversion rule for a product (e.g. 1 Box = 12 Pieces).
     */
    public function store(
        int $productId,
        CreateProductUomConversionRequest $request,
        CreateProductUomConversionAction $action
    ): JsonResponse {
        $product = Product::findOrFail($productId);
        $operatorId = $request->user()?->id;

        $conversion = $action->execute($product, $request->validated(), $operatorId);

        return response()->json([
            'success' => true,
            'message' => "Packaging unit '{$conversion->unit_name}' configured successfully.",
            'data' => new ProductUomConversionResource($conversion),
        ], 201);
    }

    /**
     * Delete a packaging UOM conversion rule.
     */
    public function destroy(int $productId, int $conversionId): JsonResponse
    {
        $conversion = ProductUomConversion::where('product_id', $productId)
            ->where('id', $conversionId)
            ->firstOrFail();

        $conversion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Packaging unit conversion rule removed.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateProductVariantRequest;
use App\Http\Resources\V1\ProductVariantResource;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    /**
     * Update an individual product variant.
     */
    public function update(UpdateProductVariantRequest $request, int $productId, int $variantId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $variant = ProductVariant::where('product_id', $product->id)->findOrFail($variantId);

        $variant->update($request->validated());
        $variant->load('attributeValues.attribute');

        return response()->json([
            'success' => true,
            'message' => "Product variant '{$variant->sku}' updated successfully.",
            'data' => new ProductVariantResource($variant),
        ]);
    }

    /**
     * Delete an individual product variant.
     */
    public function destroy(int $productId, int $variantId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $variant = ProductVariant::where('product_id', $product->id)->findOrFail($variantId);

        // Enterprise Safeguard: Prevent deleting variant with remaining stock
        if ($variant->stock > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete variant '{$variant->sku}' because it currently has physical stock ({$variant->stock}). Adjust stock to zero or mark variant inactive instead.",
            ], 422);
        }

        $variant->delete();

        // If product has no more variants, sync has_variants flag
        if ($product->variants()->count() === 0) {
            $product->update(['has_variants' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => "Product variant '{$variant->sku}' deleted successfully.",
        ]);
    }
}

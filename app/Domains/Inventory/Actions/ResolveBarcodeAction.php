<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;

class ResolveBarcodeAction
{
    /**
     * Resolve a scanned barcode or SKU across master products and granular variants.
     *
     * @return array<string, mixed>|null
     */
    public function execute(string $barcode): ?array
    {
        $barcode = trim($barcode);

        if (empty($barcode)) {
            return null;
        }

        // 1. First priority: Check exact Variant Barcode / SKU
        $variant = ProductVariant::with(['product.category', 'product.uom', 'attributeValues.attribute'])
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)
                    ->orWhere('sku', $barcode);
            })
            ->where('is_active', true)
            ->first();

        if ($variant) {
            return [
                'type' => 'variant',
                'variant' => $variant,
                'product' => $variant->product,
            ];
        }

        // 2. Second priority: Check Master Product Barcode
        $product = Product::with(['category', 'uom', 'variants.attributeValues.attribute', 'options'])
            ->where('barcode', $barcode)
            ->first();

        if ($product) {
            return [
                'type' => 'product',
                'product' => $product,
            ];
        }

        return null;
    }
}

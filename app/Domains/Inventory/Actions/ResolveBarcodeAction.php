<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Domains\Inventory\Models\ProductVariant;

class ResolveBarcodeAction
{
    /**
     * Resolve a scanned barcode or SKU across packaging UOMs, product variants, and master products.
     *
     * @return array<string, mixed>|null
     */
    public function execute(string $barcode): ?array
    {
        $barcode = trim($barcode);

        if (empty($barcode)) {
            return null;
        }

        // 1. First priority: Check exact Packaging UOM Barcode / SKU (Box, Carton, Drum)
        $uomConversion = ProductUomConversion::with(['product.category', 'product.uom', 'uom'])
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)
                    ->orWhere('sku', $barcode);
            })
            ->first();

        if ($uomConversion) {
            return [
                'type' => 'uom_conversion',
                'uom_conversion' => $uomConversion,
                'product' => $uomConversion->product,
                'unit_name' => $uomConversion->unit_name,
                'conversion_factor' => (float) $uomConversion->conversion_factor,
                'sell_price' => (float) $uomConversion->sell_price,
            ];
        }

        // 2. Second priority: Check exact Variant Barcode / SKU
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

        // 3. Third priority: Check Master Product Barcode
        $product = Product::with(['category', 'uom', 'uomConversions.uom', 'variants.attributeValues.attribute', 'options'])
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

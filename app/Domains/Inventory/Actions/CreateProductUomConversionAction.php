<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use InvalidArgumentException;

class CreateProductUomConversionAction
{
    /**
     * Create or attach a packaging UOM conversion rule to a product.
     * e.g., 1 Box = 12 Pieces with its own barcode and bulk sell price.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     * @throws InvalidArgumentException
     */
    public function execute(Product $product, array $data, ?int $operatorId = null): ProductUomConversion
    {
        $uomId = (int) ($data['uom_id'] ?? 0);
        $factor = (float) ($data['conversion_factor'] ?? 0);
        $sellPrice = (float) ($data['sell_price'] ?? 0);
        $costPrice = isset($data['cost_price']) ? (float) $data['cost_price'] : null;
        $barcode = ! empty($data['barcode']) ? trim($data['barcode']) : null;
        $sku = ! empty($data['sku']) ? trim($data['sku']) : null;
        $unitName = trim($data['unit_name'] ?? '');

        // Validation 1: Factor must be strictly greater than 0
        if ($factor <= 0) {
            throw new InvalidArgumentException('Conversion factor must be strictly greater than zero.');
        }

        // Validation 2: Cannot convert product to its own Base UOM
        if ($product->uom_id === $uomId) {
            throw new DomainException("Cannot create conversion rule for Base UOM ({$product->uom?->name}). Base UOM is always 1:1.");
        }

        // Validation 3: Target UOM must exist
        $uom = UnitOfMeasure::findOrFail($uomId);

        // Validation 4: Cannot duplicate conversion for the same UOM
        $existing = ProductUomConversion::where('product_id', $product->id)
            ->where('uom_id', $uomId)
            ->first();

        if ($existing) {
            throw new DomainException("Product already has a conversion rule configured for UOM '{$uom->name}'.");
        }

        // Validation 5: Barcode uniqueness
        if ($barcode) {
            if ($barcode === $product->barcode || ProductUomConversion::where('barcode', $barcode)->exists()) {
                throw new DomainException("Barcode '{$barcode}' is already in use by another product or packaging unit.");
            }
        }

        if (empty($unitName)) {
            $unitName = "{$uom->name} of {$factor}";
        }

        $conversion = ProductUomConversion::create([
            'product_id' => $product->id,
            'uom_id' => $uomId,
            'unit_name' => $unitName,
            'conversion_factor' => $factor,
            'barcode' => $barcode,
            'sku' => $sku,
            'cost_price' => $costPrice,
            'sell_price' => $sellPrice,
            'is_purchase_default' => (bool) ($data['is_purchase_default'] ?? false),
            'is_sale_default' => (bool) ($data['is_sale_default'] ?? false),
        ]);

        AuditLog::create([
            'staff_id' => $operatorId,
            'module' => 'inventory',
            'action' => 'product_uom_conversion_created',
            'severity' => 'info',
            'resource_type' => 'product_uom_conversions',
            'resource_id' => (string) $conversion->id,
            'description' => "Configured packaging unit '{$unitName}' (1x = {$factor} base units) for {$product->name}",
            'diffs' => [
                'product_id' => $product->id,
                'uom_id' => $uomId,
                'conversion_factor' => $factor,
                'sell_price' => $sellPrice,
            ],
            'created_at' => now(),
        ]);

        return $conversion->load(['uom', 'product']);
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\AttributeValue;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateVariantMatrixAction
{
    /**
     * Generate Cartesian Product Variant Matrix for a product from multi-axis attributes (e.g. Size x Color).
     *
     * @param  array<int>  $attributeValueIds  List of selected AttributeValue IDs across 1 or more attributes
     * @param  array<string, mixed>  $config  Optional price, cost_price, initial_stock overrides
     * @return Collection<int, ProductVariant>
     * @throws DomainException
     */
    public function execute(Product $product, array $attributeValueIds, array $config = [], ?int $operatorId = null): Collection
    {
        return DB::transaction(function () use ($product, $attributeValueIds, $config, $operatorId) {
            if ($product->is_labor) {
                throw new DomainException('Cannot generate physical variant matrix for a service/labor fee.');
            }

            if (empty($attributeValueIds)) {
                throw new DomainException('Please select at least one attribute value to generate variants.');
            }

            // 1. Fetch values and group by attribute_id
            $values = AttributeValue::with('attribute')
                ->whereIn('id', $attributeValueIds)
                ->get();

            if ($values->isEmpty()) {
                throw new DomainException('Selected attribute values not found.');
            }

            $grouped = $values->groupBy('attribute_id')->values()->toArray();

            // 2. Cartesian Product Generator
            $combinations = $this->cartesianProduct($grouped);

            $createdVariants = new Collection();
            $basePrice = isset($config['price']) ? (float) $config['price'] : (float) $product->sell_price;
            $baseCost = isset($config['cost_price']) ? (float) $config['cost_price'] : (float) $product->cost_price;
            $initialStock = isset($config['initial_stock']) ? (int) $config['initial_stock'] : 0;

            foreach ($combinations as $index => $combo) {
                // Generate Structured SKU: e.g. "8851234567890-S-RED"
                $tokens = array_map(function ($item) {
                    return strtoupper(Str::slug($item['value']));
                }, $combo);

                $skuSuffix = implode('-', $tokens);
                $sku = "{$product->barcode}-{$skuSuffix}";
                $barcode = "{$product->barcode}-V" . sprintf('%03d', $index + 1);

                // Avoid duplicate SKUs if variant already exists
                $existing = ProductVariant::where('sku', $sku)->first();
                if ($existing) {
                    $createdVariants->push($existing->load('attributeValues.attribute'));
                    continue;
                }

                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'price' => $basePrice,
                    'cost_price' => $baseCost,
                    'stock' => $initialStock,
                    'is_active' => true,
                ]);

                // Attach pivot values
                $valueIds = array_column($combo, 'id');
                $variant->attributeValues()->sync($valueIds);

                $createdVariants->push($variant->load('attributeValues.attribute'));
            }

            // 3. Mark Product as has_variants and synchronize single source of truth stock
            $product->update([
                'has_variants' => true,
                'stock' => (int) $product->variants()->sum('stock'),
            ]);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'inventory',
                'action' => 'variant_matrix_generated',
                'severity' => 'info',
                'resource_type' => 'products',
                'resource_id' => (string) $product->id,
                'description' => "Generated {$createdVariants->count()} variants for product '{$product->name}'.",
                'diffs' => [
                    'product_id' => $product->id,
                    'variants_count' => $createdVariants->count(),
                    'total_stock' => $product->fresh()->stock,
                ],
                'created_at' => now(),
            ]);

            return $createdVariants;
        });
    }

    /**
     * Compute Cartesian Product of multi-dimensional array.
     */
    protected function cartesianProduct(array $input): array
    {
        $result = [[]];

        foreach ($input as $key => $values) {
            $append = [];
            foreach ($result as $product) {
                foreach ($values as $item) {
                    $append[] = array_merge($product, [$item]);
                }
            }
            $result = $append;
        }

        return $result;
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;
use App\Exceptions\InsufficientStockException;

class DeductStockAction
{
    /**
     * Deduct inventory using Pessimistic Row Locking to prevent race conditions.
     *
     * @throws InsufficientStockException
     */
    public function execute(int $productId, int $qty, ?int $productOptionId = null, ?int $warehouseId = null, ?int $productVariantId = null): Product
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($productId, $qty, $productOptionId, $warehouseId, $productVariantId) {
            // 1. Lock Product Row for Update
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // Labor charges do not deduct physical inventory
            if ($product->is_labor) {
                return $product;
            }

            // 2. Validate Available Master Stock
            if ($product->stock < $qty) {
                throw new InsufficientStockException(
                    "Product '{$product->name}' has insufficient stock. Requested: {$qty}, Available: {$product->stock}."
                );
            }

            // 3. Lock & Validate Legacy Variant Option if provided
            $option = null;
            if ($productOptionId) {
                $option = ProductOption::where('id', $productOptionId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($option && $option->stock < $qty) {
                    throw new InsufficientStockException(
                        "Variant '{$option->name}' has insufficient stock. Requested: {$qty}, Available: {$option->stock}."
                    );
                }
            }

            // 4. Lock & Validate Multi-Attribute Product Variant if provided
            $variant = null;
            if ($productVariantId) {
                $variant = \App\Domains\Inventory\Models\ProductVariant::where('id', $productVariantId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($variant && $variant->stock < $qty) {
                    throw new InsufficientStockException(
                        "Variant '{$variant->sku}' has insufficient stock. Requested: {$qty}, Available: {$variant->stock}."
                    );
                }
            }

            // 5. Lock & Validate Specific Warehouse Location Stock strictly
            $whStock = null;
            if ($warehouseId) {
                $whStock = \App\Domains\Inventory\Models\WarehouseStock::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->when($productOptionId, fn ($q) => $q->where('product_option_id', $productOptionId))
                    ->lockForUpdate()
                    ->first();

                $wh = \App\Domains\Inventory\Models\Warehouse::find($warehouseId);
                $whName = $wh ? $wh->name : "Warehouse #{$warehouseId}";

                if (! $whStock || $whStock->stock_qty < $qty) {
                    $available = $whStock ? $whStock->stock_qty : 0;
                    throw new InsufficientStockException(
                        "{$whName} has insufficient stock for '{$product->name}'. Available: {$available}, Requested: {$qty}."
                    );
                }
            }

            // 6. Atomically Apply Decrements across all locked entities
            $product->decrement('stock', $qty);

            if ($option) {
                $option->decrement('stock', $qty);
            }

            if ($variant) {
                $variant->decrement('stock', $qty);
            }

            if ($whStock) {
                $whStock->decrement('stock_qty', $qty);
            }

            $freshProduct = $product->fresh();

            // 7. Broadcast Real-Time Reverb WebSocket Event
            event(new \App\Domains\Inventory\Events\StockUpdatedEvent($freshProduct, -$qty, 'SALE'));

            return $freshProduct;
        });
    }
}

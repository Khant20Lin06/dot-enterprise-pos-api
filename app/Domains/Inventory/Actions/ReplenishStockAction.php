<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\WarehouseStock;

class ReplenishStockAction
{
    /**
     * Increase inventory upon purchasing or return restocking.
     * Enforces consistent row locking across Product master, Variant, Option, and WarehouseStock.
     */
    public function execute(
        int $productId,
        int $qty,
        ?int $productOptionId = null,
        ?int $warehouseId = null,
        ?int $productVariantId = null
    ): Product {
        $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

        if ($product->is_labor) {
            return $product;
        }

        $product->increment('stock', $qty);
        $freshProduct = $product->fresh();

        // 1. If legacy option provided, lock and increment
        if ($productOptionId) {
            $option = ProductOption::where('id', $productOptionId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($option) {
                $option->increment('stock', $qty);
            }
        }

        // 2. If multi-attribute product variant provided, lock and increment
        if ($productVariantId) {
            $variant = ProductVariant::where('id', $productVariantId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($variant) {
                $variant->increment('stock', $qty);
            }
        }

        // 3. If specific warehouse provided, increment warehouse stock
        if ($warehouseId) {
            $whStock = WarehouseStock::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_option_id' => $productOptionId,
                ],
                ['stock_qty' => 0]
            );

            WarehouseStock::where('id', $whStock->id)
                ->lockForUpdate()
                ->increment('stock_qty', $qty);
        }

        // Broadcast Real-Time Reverb WebSocket Event
        event(new \App\Domains\Inventory\Events\StockUpdatedEvent($freshProduct, +$qty, 'REPLENISH'));

        return $freshProduct;
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;

class ReplenishStockAction
{
    /**
     * Increase inventory upon purchasing or return restocking.
     */
    public function execute(int $productId, int $qty, ?int $productOptionId = null, ?int $warehouseId = null): Product
    {
        $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

        if ($product->is_labor) {
            return $product;
        }

        $product->increment('stock', $qty);
        $freshProduct = $product->fresh();

        if ($productOptionId) {
            $option = ProductOption::where('id', $productOptionId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($option) {
                $option->increment('stock', $qty);
            }
        }

        // If specific warehouse provided, increment warehouse stock
        if ($warehouseId) {
            $whStock = \App\Domains\Inventory\Models\WarehouseStock::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_option_id' => $productOptionId,
                ],
                ['stock_qty' => 0]
            );

            \App\Domains\Inventory\Models\WarehouseStock::where('id', $whStock->id)
                ->lockForUpdate()
                ->increment('stock_qty', $qty);
        }

        // Broadcast Real-Time Reverb WebSocket Event
        event(new \App\Domains\Inventory\Events\StockUpdatedEvent($freshProduct, +$qty, 'REPLENISH'));

        return $freshProduct;
    }
}

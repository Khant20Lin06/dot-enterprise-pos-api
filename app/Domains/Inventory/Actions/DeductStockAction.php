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
    public function execute(int $productId, int $qty, ?int $productOptionId = null, ?int $warehouseId = null): Product
    {
        // 1. Lock Product Row for Update
        $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

        // Labor charges do not deduct physical inventory
        if ($product->is_labor) {
            return $product;
        }

        // 2. Validate Available Stock
        if ($product->stock < $qty) {
            throw new InsufficientStockException(
                "Product '{$product->name}' has insufficient stock. Requested: {$qty}, Available: {$product->stock}."
            );
        }

        // 3. Deduct Master Stock
        $product->decrement('stock', $qty);
        $freshProduct = $product->fresh();

        // 4. If Variant Option provided, lock and deduct variant stock as well
        if ($productOptionId) {
            $option = ProductOption::where('id', $productOptionId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($option) {
                if ($option->stock < $qty) {
                    throw new InsufficientStockException(
                        "Variant '{$option->name}' has insufficient stock. Requested: {$qty}, Available: {$option->stock}."
                    );
                }
                $option->decrement('stock', $qty);
            }
        }

        // 5. If specific physical Warehouse location provided, lock and deduct warehouse stock
        if ($warehouseId) {
            $whStock = \App\Domains\Inventory\Models\WarehouseStock::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->when($productOptionId, fn ($q) => $q->where('product_option_id', $productOptionId))
                ->lockForUpdate()
                ->first();

            if ($whStock) {
                if ($whStock->stock_qty < $qty) {
                    $wh = \App\Domains\Inventory\Models\Warehouse::find($warehouseId);
                    $whName = $wh ? $wh->name : "Warehouse #{$warehouseId}";
                    throw new InsufficientStockException(
                        "{$whName} has insufficient stock for '{$product->name}'. Available: {$whStock->stock_qty}, Requested: {$qty}."
                    );
                }
                $whStock->decrement('stock_qty', $qty);
            }
        }

        // 6. Broadcast Real-Time Reverb WebSocket Event
        event(new \App\Domains\Inventory\Events\StockUpdatedEvent($freshProduct, -$qty, 'SALE'));

        return $freshProduct;
    }
}

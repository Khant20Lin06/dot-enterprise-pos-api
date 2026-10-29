<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;

class ReplenishStockAction
{
    /**
     * Increase inventory upon purchasing or return restocking.
     */
    public function execute(int $productId, int $qty, ?int $productOptionId = null): Product
    {
        $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

        if ($product->is_labor) {
            return $product;
        }

        $product->increment('stock', $qty);

        if ($productOptionId) {
            $option = ProductOption::where('id', $productOptionId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($option) {
                $option->increment('stock', $qty);
            }
        }

        return $product->fresh();
    }
}

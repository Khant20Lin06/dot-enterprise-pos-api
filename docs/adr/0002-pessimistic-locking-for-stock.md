# ADR 0002: Pessimistic Row Locking for Inventory & Checkout Concurrency

## Status
**ACCEPTED** (2026-10-30)

## Context
In retail POS and workshop counters, multiple cashiers or mechanics may ring up the last remaining unit of an item (e.g. Engine Oil, Brake Pads) at the exact same second.
* **Without locking:** Both transactions read `stock = 1`, both succeed, resulting in `stock = -1` (Overselling / Data corruption).
* **Optimistic locking (version checking):** Rejects the second cashier with a conflict exception, forcing them to re-scan the entire cart, frustrating both cashier and customer.

## Decision
We enforce **Pessimistic Row-Level Locking (`SELECT ... FOR UPDATE`)** inside explicit database transactions:
```php
DB::transaction(function () use ($productId, $qty) {
    $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();
    if ($product->stock < $qty) {
        throw new InsufficientStockException("Insufficient stock for {$product->name}");
    }
    $product->decrement('stock', $qty);
});
```

## Consequences
* **Positive:** 100% guarantee against negative inventory and race conditions.
* **Positive:** Transactions are serialized at the database row level, holding the lock only for milliseconds before commit.
* **Negative:** Transactions must be kept fast (no external HTTP calls inside the transaction block) to prevent database deadlocks.

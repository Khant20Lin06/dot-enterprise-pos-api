<?php

namespace App\Domains\Inventory\Events;

use App\Domains\Inventory\Models\Product;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Product $product,
        public int $delta = 0,
        public string $reason = 'SALE'
    ) {}

    /**
     * Broadcast on public stock channel for instant multi-counter inventory synchronization.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('stock.channel'),
        ];
    }

    /**
     * Broadcast event name for frontend listeners.
     */
    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    /**
     * Data payload sent to WebSocket subscribers.
     */
    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'barcode' => $this->product->barcode,
            'stock' => $this->product->stock,
            'min_stock' => $this->product->min_stock,
            'is_low_stock' => $this->product->isLowStock(),
            'delta' => $this->delta,
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

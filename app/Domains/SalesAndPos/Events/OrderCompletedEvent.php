<?php

namespace App\Domains\SalesAndPos\Events;

use App\Domains\SalesAndPos\Models\SalesOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCompletedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SalesOrder $order
    ) {}

    /**
     * Broadcast on pos.orders channel for live dashboard & kitchen/counter feeds.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('pos.orders'),
        ];
    }

    /**
     * Broadcast event name for frontend listeners.
     */
    public function broadcastAs(): string
    {
        return 'order.completed';
    }

    /**
     * Data payload sent to WebSocket subscribers.
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_no' => $this->order->order_no,
            'customer_name' => $this->order->customer?->name ?? 'Walk-In Customer',
            'cashier_name' => $this->order->cashier?->name ?? 'Cashier',
            'total_amount' => (float) $this->order->total_amount,
            'paid_amount' => (float) $this->order->paid_amount,
            'due_amount' => (float) $this->order->due_amount,
            'items_count' => $this->order->items->count(),
            'status' => $this->order->status,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

<?php

namespace App\Domains\SalesAndPos\Events;

use App\Domains\SalesAndPos\Models\SalesReturn;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SalesReturnProcessedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SalesReturn $return
    ) {}

    /**
     * Broadcast on pos.orders channel for live counter and supervisor notifications.
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
        return 'return.processed';
    }

    /**
     * Data payload sent to WebSocket subscribers.
     */
    public function broadcastWith(): array
    {
        return [
            'return_id' => $this->return->id,
            'return_no' => $this->return->return_no,
            'sales_order_no' => $this->return->salesOrder?->order_no,
            'customer_name' => $this->return->customer?->name ?? 'Customer',
            'cashier_name' => $this->return->cashier?->name ?? 'Cashier',
            'total_refund_amount' => (float) $this->return->total_refund_amount,
            'refund_method' => $this->return->refund_method,
            'status' => $this->return->status,
            'items_count' => $this->return->items()->count(),
            'created_at' => $this->return->created_at?->toIso8601String(),
        ];
    }
}

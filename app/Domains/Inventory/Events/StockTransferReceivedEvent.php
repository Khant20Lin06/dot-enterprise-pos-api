<?php

namespace App\Domains\Inventory\Events;

use App\Domains\Inventory\Models\StockTransfer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferReceivedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StockTransfer $transfer
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('stock.channel'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'transfer.received';
    }

    public function broadcastWith(): array
    {
        return [
            'transfer_id' => $this->transfer->id,
            'transfer_no' => $this->transfer->transfer_no,
            'source_warehouse' => $this->transfer->sourceWarehouse?->name,
            'destination_warehouse' => $this->transfer->destinationWarehouse?->name,
            'status' => $this->transfer->status,
            'items_count' => $this->transfer->items()->count(),
            'received_at' => $this->transfer->received_at?->toIso8601String(),
        ];
    }
}

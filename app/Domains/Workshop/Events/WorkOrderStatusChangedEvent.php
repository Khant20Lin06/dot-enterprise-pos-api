<?php

namespace App\Domains\Workshop\Events;

use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOrderStatusChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public ?string $previousStatus = null
    ) {}

    /**
     * Broadcast on workshop.bay channel for live workshop TV and mechanic terminal displays.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('workshop.bay'),
        ];
    }

    /**
     * Broadcast event name for frontend listeners.
     */
    public function broadcastAs(): string
    {
        return 'workshop.status.changed';
    }

    /**
     * Data payload sent to WebSocket subscribers.
     */
    public function broadcastWith(): array
    {
        return [
            'work_order_id' => $this->workOrder->id,
            'job_card_no' => $this->workOrder->job_card_no,
            'vehicle_plate' => $this->workOrder->vehicle?->plate_number ?? 'N/A',
            'customer_name' => $this->workOrder->customer?->name ?? 'Walk-In Customer',
            'bay' => $this->workOrder->bay,
            'status' => $this->workOrder->status,
            'previous_status' => $this->previousStatus,
            'assigned_mechanic_name' => $this->workOrder->mechanic?->name ?? 'Unassigned',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

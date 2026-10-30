<?php

namespace App\Domains\SalesAndPos\Events;

use App\Domains\SalesAndPos\Models\PosSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PosSessionClosedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PosSession $session
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('pos.sessions'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.closed';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->session->id,
            'sessionNo' => $this->session->session_no,
            'cashierId' => $this->session->cashier_id,
            'terminalId' => $this->session->terminal_id,
            'totalSales' => (float) $this->session->total_sales,
            'totalRefunds' => (float) $this->session->total_refunds,
            'expectedCash' => (float) $this->session->expected_cash,
            'countedCash' => (float) $this->session->counted_cash,
            'cashVariance' => (float) $this->session->cash_variance,
            'closedAt' => $this->session->closed_at?->toIso8601String(),
        ];
    }
}

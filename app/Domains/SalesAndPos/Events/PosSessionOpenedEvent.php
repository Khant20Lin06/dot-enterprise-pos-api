<?php

namespace App\Domains\SalesAndPos\Events;

use App\Domains\SalesAndPos\Models\PosSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PosSessionOpenedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'session.opened';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->session->id,
            'sessionNo' => $this->session->session_no,
            'cashierId' => $this->session->cashier_id,
            'terminalId' => $this->session->terminal_id,
            'openingFloat' => (float) $this->session->opening_float,
            'openedAt' => $this->session->opened_at?->toIso8601String(),
        ];
    }
}

<?php

use Illuminate\Support\Facades\Broadcast;

// 1. Public Multi-Counter Inventory Channel (No auth needed for live counter sync)
Broadcast::channel('stock.channel', function () {
    return true;
});

// 2. POS Orders Channel (For active cashiers and supervisors)
Broadcast::channel('pos.orders', function ($user) {
    return true;
});

// 3. Workshop Bay Monitor Channel (For service advisors and technicians)
Broadcast::channel('workshop.bay', function ($user) {
    return true;
});

// 4. Presence Channel: Active POS Counters (Tracks which cashiers are logged in)
Broadcast::channel('pos.counter.{counterId}', function ($user, $counterId) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role?->code ?? 'CASHIER',
        'counter' => $counterId,
    ];
});

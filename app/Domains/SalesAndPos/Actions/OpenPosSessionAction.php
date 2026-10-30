<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\SalesAndPos\Events\PosSessionOpenedEvent;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Services\AtomicSequenceService;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OpenPosSessionAction
{
    public function __construct(
        protected AtomicSequenceService $sequenceService
    ) {}

    /**
     * Open a new cash register shift session with opening float.
     * Enforces one active session per cashier and per terminal.
     * Protected against concurrent double-click race conditions via atomic locks (HIGH-01).
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, int $cashierId): PosSession
    {
        $lockKey = "pos_session_open_cashier_{$cashierId}";
        $lock = Cache::lock($lockKey, 10);

        return $lock->block(5, function () use ($data, $cashierId) {
            return DB::transaction(function () use ($data, $cashierId) {
                $terminalId = trim($data['terminal_id'] ?? 'COUNTER-01');
                $openingFloat = isset($data['opening_float']) ? (float) $data['opening_float'] : 0.00;
                $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
                $notes = $data['opening_notes'] ?? null;

                if ($openingFloat < 0) {
                    throw new DomainException('Opening float cannot be negative.');
                }

                // 1. Concurrency Guard: Enforce single active session per cashier
                $existingCashierSession = PosSession::where('cashier_id', $cashierId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($existingCashierSession) {
                    throw new DomainException(
                        "Cashier already has an active open shift (#{$existingCashierSession->session_no}) on terminal '{$existingCashierSession->terminal_id}'. Please close it first."
                    );
                }

                // 2. Concurrency Guard: Enforce single active session per physical terminal
                $existingTerminalSession = PosSession::where('terminal_id', $terminalId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($existingTerminalSession) {
                    throw new DomainException(
                        "Terminal '{$terminalId}' is already in use by active shift #{$existingTerminalSession->session_no}."
                    );
                }

                // 3. Generate Sequential Session Number
                $sessionNo = $this->sequenceService->getNextSequence('SHIFT');

                $session = PosSession::create([
                    'session_no' => $sessionNo,
                    'cashier_id' => $cashierId,
                    'terminal_id' => $terminalId,
                    'warehouse_id' => $warehouseId,
                    'opening_float' => $openingFloat,
                    'status' => 'open',
                    'opened_at' => now(),
                    'opening_notes' => $notes,
                ]);

                // 4. Record Audit Log
                AuditLog::create([
                    'staff_id' => $cashierId,
                    'module' => 'sales_and_pos',
                    'action' => 'pos_session_opened',
                    'severity' => 'info',
                    'resource_type' => 'pos_sessions',
                    'resource_id' => (string) $session->id,
                    'description' => "Cash register shift #{$sessionNo} opened on {$terminalId} with float {$openingFloat} Ks",
                    'diffs' => [
                        'session_no' => $sessionNo,
                        'terminal_id' => $terminalId,
                        'opening_float' => $openingFloat,
                    ],
                    'created_at' => now(),
                ]);

                // 5. Broadcast Reverb Event
                event(new PosSessionOpenedEvent($session));

                return $session->fresh(['cashier', 'warehouse']);
            });
        });
    }
}

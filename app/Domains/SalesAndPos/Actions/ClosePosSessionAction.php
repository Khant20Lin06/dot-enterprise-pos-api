<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\SalesAndPos\Events\PosSessionClosedEvent;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesReturn;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClosePosSessionAction
{
    /**
     * Close a POS shift session with blind cash count reconciliation.
     * Calculates expected drawer cash vs counted cash and identifies any variance (Over/Short).
     *
     * @param  array<string, mixed>  $data  contains 'counted_cash', optional 'closing_notes'
     * @throws DomainException
     */
    public function execute(PosSession $session, array $data, ?int $operatorId = null): PosSession
    {
        return DB::transaction(function () use ($session, $data, $operatorId) {
            // Lock session row
            $session = PosSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw new DomainException("Shift #{$session->session_no} is already closed.");
            }

            $countedCash = (float) ($data['counted_cash'] ?? 0.00);
            if ($countedCash < 0) {
                throw new DomainException('Counted cash cannot be negative.');
            }

            $closingNotes = $data['closing_notes'] ?? null;

            // 1. Reconcile Completed Sales Orders for this session
            $orders = SalesOrder::where('pos_session_id', $session->id)
                ->where('status', '!=', 'Voided')
                ->get();

            $totalSales = (float) $orders->sum('total_amount');
            $orderIds = $orders->pluck('id')->toArray();

            // 2. Reconcile Payment Splits by Tender Method
            $splits = empty($orderIds) ? collect() : PaymentSplit::whereIn('sales_order_id', $orderIds)->get();

            $cashSales = (float) $splits->filter(fn ($s) => strtolower($s->method) === 'cash')->sum('amount');
            $kpaySales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['kpay', 'kbzpay']))->sum('amount');
            $waveSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['wave', 'wavepay']))->sum('amount');
            $cardSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['card', 'credit card', 'pos card']))->sum('amount');
            $creditSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['credit', 'due']))->sum('amount');

            // 3. Reconcile Sales Returns / Refunds for this session
            $returns = SalesReturn::where('pos_session_id', $session->id)->get();
            $totalRefunds = (float) $returns->sum('total_refund_amount');
            $cashRefunds = (float) $returns->filter(fn ($r) => strtolower($r->refund_method) === 'cash')->sum('total_refund_amount');

            // 3b. Reconcile Customer Credit Debt Settlements collected during this shift session
            $creditSettlements = \App\Domains\CreditAndLedger\Models\CreditSettlement::where('pos_session_id', $session->id)->get();
            $creditSettlementCash = (float) $creditSettlements->filter(fn ($cs) => strtolower($cs->payment_method) === 'cash')->sum('amount');

            // Account for change given on cash sales (overpayment)
            $totalChangeGiven = (float) $orders->sum('change_amount');
            $netCashSales = max(0.00, $cashSales - $totalChangeGiven);

            // 4. Mathematical Drawer Formula: Expected Cash = Opening Float + Net Cash Sales + Credit Collections - Cash Refunds
            $expectedCash = round((float) $session->opening_float + $netCashSales + $creditSettlementCash - $cashRefunds, 2);
            $cashVariance = round($countedCash - $expectedCash, 2);

            // 5. Update and Close Session
            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'counted_cash' => $countedCash,
                'expected_cash' => $expectedCash,
                'cash_variance' => $cashVariance,
                'total_sales' => $totalSales,
                'total_refunds' => $totalRefunds,
                'cash_sales' => $cashSales,
                'kpay_sales' => $kpaySales,
                'wave_sales' => $waveSales,
                'card_sales' => $cardSales,
                'credit_sales' => $creditSales,
                'closing_notes' => $closingNotes,
            ]);

            // 6. Audit Trail: Highlight Variance Severity
            $severity = ($cashVariance != 0.0) ? 'warning' : 'info';
            AuditLog::create([
                'staff_id' => $operatorId ?: $session->cashier_id,
                'module' => 'pos',
                'action' => 'session_closed',
                'severity' => $severity,
                'resource_type' => 'pos_sessions',
                'resource_id' => (string) $session->id,
                'description' => "Shift #{$session->session_no} closed. Counted: {$countedCash} Ks, Expected: {$expectedCash} Ks, Variance: {$cashVariance} Ks",
                'diffs' => [
                    'session_no' => $session->session_no,
                    'counted_cash' => $countedCash,
                    'expected_cash' => $expectedCash,
                    'cash_variance' => $cashVariance,
                    'total_sales' => $totalSales,
                    'total_refunds' => $totalRefunds,
                ],
                'created_at' => now(),
            ]);

            event(new PosSessionClosedEvent($session));

            return $session->fresh();
        });
    }
}

<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesReturn;

class GenerateShiftAuditReportAction
{
    /**
     * Compile X-Report (Interim mid-shift reading) or Z-Report (Final end-of-shift reading).
     *
     * @return array<string, mixed>
     */
    public function execute(PosSession $session): array
    {
        $session->loadMissing(['cashier', 'warehouse']);

        $isClosed = $session->isClosed();
        $reportType = $isClosed ? 'Z-Report' : 'X-Report';

        $orders = SalesOrder::where('pos_session_id', $session->id)
            ->where('status', '!=', 'Voided')
            ->get();

        $orderIds = $orders->pluck('id')->toArray();
        $splits = empty($orderIds) ? collect() : PaymentSplit::whereIn('sales_order_id', $orderIds)->get();

        $returns = SalesReturn::where('pos_session_id', $session->id)->get();

        $cashSales = (float) $splits->filter(fn ($s) => strtolower($s->method) === 'cash')->sum('amount');
        $kpaySales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['kpay', 'kbzpay']))->sum('amount');
        $waveSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['wave', 'wavepay']))->sum('amount');
        $cardSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['card', 'credit card', 'pos card']))->sum('amount');
        $creditSales = (float) $splits->filter(fn ($s) => in_array(strtolower($s->method), ['credit', 'due']))->sum('amount');

        $totalSales = (float) $orders->sum('total_amount');
        $totalRefunds = (float) $returns->sum('total_refund_amount');
        $cashRefunds = (float) $returns->filter(fn ($r) => strtolower($r->refund_method) === 'cash')->sum('total_refund_amount');

        $expectedCash = round((float) $session->opening_float + $cashSales - $cashRefunds, 2);
        $countedCash = $isClosed ? (float) $session->counted_cash : null;
        $variance = $isClosed ? (float) $session->cash_variance : null;

        $now = now();
        $durationMinutes = $session->opened_at ? (int) $session->opened_at->diffInMinutes($session->closed_at ?: $now) : 0;

        return [
            'reportType' => $reportType,
            'isFinal' => $isClosed,
            'sessionId' => $session->id,
            'sessionNo' => $session->session_no,
            'terminalId' => $session->terminal_id,
            'warehouse' => [
                'id' => $session->warehouse?->id,
                'name' => $session->warehouse?->name ?? 'Default Branch',
            ],
            'cashier' => [
                'id' => $session->cashier?->id,
                'name' => $session->cashier?->name,
                'email' => $session->cashier?->email,
            ],
            'timing' => [
                'openedAt' => $session->opened_at?->toIso8601String(),
                'closedAt' => $session->closed_at?->toIso8601String(),
                'durationMinutes' => $durationMinutes,
                'generatedAt' => $now->toIso8601String(),
            ],
            'financialSummary' => [
                'openingFloat' => (float) $session->opening_float,
                'ordersCount' => $orders->count(),
                'grossSales' => $totalSales,
                'returnsCount' => $returns->count(),
                'grossRefunds' => $totalRefunds,
                'netSales' => round($totalSales - $totalRefunds, 2),
            ],
            'tenderBreakdown' => [
                'cash' => [
                    'sales' => $cashSales,
                    'refunds' => $cashRefunds,
                    'net' => round($cashSales - $cashRefunds, 2),
                ],
                'kpay' => $kpaySales,
                'wavepay' => $waveSales,
                'card' => $cardSales,
                'credit' => $creditSales,
            ],
            'cashReconciliation' => [
                'openingFloat' => (float) $session->opening_float,
                'netCashTender' => round($cashSales - $cashRefunds, 2),
                'expectedDrawerCash' => $expectedCash,
                'countedCash' => $countedCash,
                'cashVariance' => $variance,
                'varianceStatus' => $variance === null ? 'Active' : ($variance == 0.0 ? 'Balanced' : ($variance > 0 ? 'Over' : 'Short')),
            ],
            'notes' => [
                'opening' => $session->opening_notes,
                'closing' => $session->closing_notes,
            ],
        ];
    }
}

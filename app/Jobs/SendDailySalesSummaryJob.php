<?php

namespace App\Jobs;

use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Domains\Workshop\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDailySalesSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $date;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(?string $date = null)
    {
        $this->date = $date ?? Carbon::today()->toDateString();
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     */
    public function handle(): array
    {
        $startOfDay = Carbon::parse($this->date)->startOfDay();
        $endOfDay = Carbon::parse($this->date)->endOfDay();

        // 1. POS Orders Aggregations
        $ordersQuery = SalesOrder::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', '!=', 'cancelled');

        $totalOrdersCount = (clone $ordersQuery)->count();
        $totalGrossSales = (float) (clone $ordersQuery)->sum('total_amount');
        $totalSubtotal = (float) (clone $ordersQuery)->sum('subtotal');
        $totalLaborSales = (float) (clone $ordersQuery)->sum('labor_total');
        $totalDiscounts = (float) (clone $ordersQuery)->sum('discount_amount');
        $totalTaxes = (float) (clone $ordersQuery)->sum('tax_amount');
        $totalPaid = (float) (clone $ordersQuery)->sum('paid_amount');
        $totalCreditDue = (float) (clone $ordersQuery)->sum('due_amount');

        // 2. Payment Method Breakdown (Cash, KPay, Card, Debt, etc.)
        $paymentSplits = PaymentSplit::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->selectRaw('method, SUM(amount) as total_collected, COUNT(*) as count')
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method => [
                    'amount' => (float) $item->total_collected,
                    'transactions' => (int) $item->count,
                ]];
            })
            ->toArray();

        // 3. Workshop Job Cards Aggregations
        $workshopCompleted = WorkOrder::whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->where('status', 'invoiced')
            ->count();

        // 4. Mechanic Labor Commissions
        $mechanicCommissionsTotal = (float) MechanicCommission::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->sum('earned_amount');

        // 5. Customer Credit Debt Settlements
        $creditSettlementsTotal = (float) CreditSettlement::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->sum('amount');

        $summaryData = [
            'date' => $this->date,
            'compiled_at' => now()->toIso8601String(),
            'pos' => [
                'total_orders' => $totalOrdersCount,
                'gross_sales' => $totalGrossSales,
                'subtotal' => $totalSubtotal,
                'labor_sales' => $totalLaborSales,
                'discounts' => $totalDiscounts,
                'taxes' => $totalTaxes,
                'collected_amount' => $totalPaid,
                'uncollected_debt' => $totalCreditDue,
            ],
            'payment_breakdown' => $paymentSplits,
            'workshop' => [
                'job_cards_invoiced' => $workshopCompleted,
                'commissions_earned' => $mechanicCommissionsTotal,
            ],
            'credit_recovery' => [
                'debt_settlements_collected' => $creditSettlementsTotal,
            ],
        ];

        // Save report to storage
        $reportsDir = storage_path('app/reports');
        if (! File::isDirectory($reportsDir)) {
            File::makeDirectory($reportsDir, 0755, true, true);
        }

        File::put("{$reportsDir}/sales_summary_{$this->date}.json", json_encode($summaryData, JSON_PRETTY_PRINT));

        // Audit Log entry
        AuditLog::create([
            'staff_id' => null,
            'module' => 'reporting',
            'action' => 'daily_sales_summary_compiled',
            'severity' => 'info',
            'resource_type' => 'reports',
            'resource_id' => 0,
            'description' => "Compiled executive daily sales summary for {$this->date}",
            'diffs' => $summaryData['pos'],
            'created_at' => now(),
        ]);

        Log::info("[SendDailySalesSummaryJob] Successfully generated daily sales report for {$this->date}");

        return $summaryData;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("[SendDailySalesSummaryJob] Daily summary generation failed for {$this->date}: " . ($exception ? $exception->getMessage() : 'Unknown error'));

        AuditLog::create([
            'staff_id' => null,
            'module' => 'reporting',
            'action' => 'daily_sales_summary_failed',
            'severity' => 'critical',
            'resource_type' => 'reports',
            'resource_id' => 0,
            'description' => "Daily summary compilation failed: " . ($exception ? $exception->getMessage() : 'Max tries reached'),
            'created_at' => now(),
        ]);
    }
}

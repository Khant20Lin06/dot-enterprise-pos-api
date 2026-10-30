<?php

namespace App\Jobs;

use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Services\NodeWorkerBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateReceiptPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 60];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        $this->onQueue('receipts');
    }

    /**
     * Execute the job.
     */
    public function handle(NodeWorkerBridgeService $bridge): void
    {
        $order = SalesOrder::with(['customer', 'vehicle', 'items.product', 'paymentSplits'])->find($this->orderId);

        if (! $order) {
            Log::warning("[GenerateReceiptPdfJob] Sales order ID {$this->orderId} not found.");

            return;
        }

        // Generate receipt commands via Node.js microservice bridge or local fallback
        $receiptData = $bridge->generateEscposReceipt($order);
        $asciiReceipt = $bridge->formatAsciiReceipt($order);

        // Save generated receipt artifact to storage
        $receiptsDir = storage_path('app/receipts');
        if (! File::isDirectory($receiptsDir)) {
            File::makeDirectory($receiptsDir, 0755, true, true);
        }

        $receiptPayload = [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'generated_at' => now()->toIso8601String(),
            'total_amount' => (float) $order->total_amount,
            'paid_amount' => (float) $order->paid_amount,
            'due_amount' => (float) $order->due_amount,
            'escpos_data' => $receiptData,
            'ascii_preview' => $asciiReceipt,
        ];

        File::put("{$receiptsDir}/receipt_{$order->order_no}.json", json_encode($receiptPayload, JSON_PRETTY_PRINT));
        File::put("{$receiptsDir}/receipt_{$order->order_no}.txt", $asciiReceipt);

        // Audit Log entry
        AuditLog::create([
            'staff_id' => $order->cashier_id,
            'module' => 'sales_and_pos',
            'action' => 'receipt_generated',
            'severity' => 'info',
            'resource_type' => 'sales_orders',
            'resource_id' => $order->id,
            'description' => "Thermal receipt generated successfully for order {$order->order_no}",
            'diffs' => [
                'order_no' => $order->order_no,
                'total' => (float) $order->total_amount,
            ],
            'created_at' => now(),
        ]);

        Log::info("[GenerateReceiptPdfJob] Completed receipt generation for order {$order->order_no}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("[GenerateReceiptPdfJob] Failed generating receipt for order {$this->orderId}: " . ($exception ? $exception->getMessage() : 'Unknown error'));

        AuditLog::create([
            'staff_id' => null,
            'module' => 'sales_and_pos',
            'action' => 'receipt_generation_failed',
            'severity' => 'critical',
            'resource_type' => 'sales_orders',
            'resource_id' => $this->orderId,
            'description' => 'Thermal receipt generation failed: ' . ($exception ? $exception->getMessage() : 'Max tries reached'),
            'created_at' => now(),
        ]);
    }
}

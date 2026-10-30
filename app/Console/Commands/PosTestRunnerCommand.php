<?php

namespace App\Console\Commands;

use App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Models\WorkOrder;
use App\Jobs\SendDailySalesSummaryJob;
use Illuminate\Console\Command;

class PosTestRunnerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:test 
                            {action : Action to test (checkout, stock, job-card, settle-credit, summary)} 
                            {payload? : JSON payload or identifier}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'AI Agent & Developer instant verification tool for POS & Workshop features';

    /**
     * Execute the console command.
     */
    public function handle(
        ProcessCheckoutAction $checkoutAction,
        ConvertWorkOrderToInvoiceAction $invoiceAction,
        SettleCustomerCreditAction $settleAction
    ): int {
        $action = strtolower($this->argument('action'));
        $rawPayload = $this->argument('payload');

        $this->info("=== DOT POS AI Testing Tool: [{$action}] ===");

        try {
            switch ($action) {
                case 'stock':
                    $query = $rawPayload ?? 'PROD-OIL-5W30';
                    $product = Product::where('sku', $query)
                        ->orWhere('barcode', $query)
                        ->orWhere('id', is_numeric($query) ? (int) $query : 0)
                        ->first();

                    if (! $product) {
                        $this->error("Product not found matching: {$query}");

                        return self::FAILURE;
                    }

                    $output = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'stock' => (float) $product->stock,
                        'min_stock' => (float) $product->min_stock,
                        'retail_price' => (float) $product->retail_price,
                        'is_low_stock' => $product->isLowStock(),
                    ];
                    $this->line(json_encode($output, JSON_PRETTY_PRINT));
                    break;

                case 'checkout':
                    $payload = $rawPayload ? json_decode($rawPayload, true) : null;
                    if (! $payload) {
                        $product = Product::where('is_labor', false)->where('stock', '>', 5)->firstOrFail();
                        $customer = Customer::firstOrFail();
                        $payload = [
                            'cashier_id' => 1,
                            'customer_id' => $customer->id,
                            'items' => [
                                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => (float) $product->retail_price],
                            ],
                            'payments' => [
                                ['method' => 'Cash', 'amount' => (float) $product->retail_price],
                            ],
                            'idempotency_key' => 'CLI-TEST-' . uniqid(),
                        ];
                    }

                    $order = $checkoutAction->execute($payload);
                    $this->info("Checkout successful! Order No: {$order->order_no}");
                    $this->line(json_encode([
                        'order_id' => $order->id,
                        'order_no' => $order->order_no,
                        'total' => (float) $order->total_amount,
                        'status' => $order->status,
                        'items_count' => $order->items->count(),
                    ], JSON_PRETTY_PRINT));
                    break;

                case 'job-card':
                    $workOrderId = is_numeric($rawPayload) ? (int) $rawPayload : (WorkOrder::where('status', '!=', 'Completed / Invoiced')->value('id') ?? 1);
                    $result = $invoiceAction->execute($workOrderId, 'Cash', 1);

                    $this->info("Job Card converted! Invoiced as Order No: {$result['sales_order']->order_no}");
                    $this->line(json_encode([
                        'job_card_no' => $result['work_order']->job_card_no,
                        'sales_order_no' => $result['sales_order']->order_no,
                        'total_amount' => (float) $result['sales_order']->total_amount,
                        'commission' => $result['commission'],
                    ], JSON_PRETTY_PRINT));
                    break;

                case 'settle-credit':
                    $customerId = is_numeric($rawPayload) ? (int) $rawPayload : (Customer::where('due_amount', '>', 0)->value('id') ?? 1);
                    $customer = Customer::findOrFail($customerId);

                    $settleAmount = min(10000.00, (float) $customer->due_amount);
                    if ($settleAmount <= 0) {
                        $this->warn("Customer {$customer->name} has zero outstanding debt.");

                        return self::SUCCESS;
                    }

                    $settlement = $settleAction->execute($customerId, $settleAmount, 'KPay', 'CLI Test Settlement', 1);
                    $this->info("Credit settled! Receipt: {$settlement->receipt_no}");
                    $this->line(json_encode([
                        'customer' => $customer->name,
                        'settled_amount' => (float) $settlement->amount,
                        'remaining_due' => (float) $customer->fresh()->due_amount,
                    ], JSON_PRETTY_PRINT));
                    break;

                case 'summary':
                    $job = new SendDailySalesSummaryJob(now()->toDateString());
                    $summary = $job->handle();
                    $this->info('Daily summary generated successfully:');
                    $this->line(json_encode($summary, JSON_PRETTY_PRINT));
                    break;

                default:
                    $this->error("Unknown action: {$action}. Available: stock, checkout, job-card, settle-credit, summary");

                    return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error executing {$action}: " . $e->getMessage());

            return self::FAILURE;
        }
    }
}

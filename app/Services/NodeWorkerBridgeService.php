<?php

namespace App\Services;

use App\Domains\SalesAndPos\Models\SalesOrder;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeWorkerBridgeService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('NODE_WORKER_URL', 'http://127.0.0.1:3001'), '/');
    }

    /**
     * Check if Node.js microservice worker is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/health");

            return $response->successful() && ($response->json('status') === 'ONLINE');
        } catch (Exception $e) {
            Log::warning('[NodeWorkerBridge] Health check failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Generate high-speed barcode or QR code PNG (base64) via Node worker bwip-js.
     */
    public function generateBarcode(string $text, string $bcid = 'code128', int $scale = 3, int $height = 10): array
    {
        if (! app()->runningUnitTests() || env('NODE_WORKER_ENABLED', false)) {
            try {
                $response = Http::timeout(2)->post("{$this->baseUrl}/api/v1/barcode/generate", [
                    'text' => $text,
                    'bcid' => $bcid,
                    'scale' => $scale,
                    'height' => $height,
                    'includeText' => true,
                ]);

                if ($response->successful()) {
                    return $response->json('data', []);
                }
            } catch (Exception $e) {
                Log::warning('[NodeWorkerBridge] Barcode generation HTTP failed, using fallback: ' . $e->getMessage());
            }
        }

        // Fallback placeholder data
        return [
            'format' => 'text/plain',
            'base64' => base64_encode("BARCODE:{$bcid}:{$text}"),
            'fallback' => true,
        ];
    }

    /**
     * Generate ESC/POS byte commands for thermal receipt printing (80mm / 58mm).
     */
    public function generateEscposReceipt(SalesOrder $order): array
    {
        $order->loadMissing(['customer', 'vehicle', 'items.product', 'paymentSplits']);

        if (! app()->runningUnitTests() || env('NODE_WORKER_ENABLED', false)) {
            $payload = [
                'orderNo' => $order->order_no,
                'customerName' => $order->customer?->name ?? 'Walk-in Customer',
                'vehiclePlate' => $order->vehicle?->license_plate ?? 'N/A',
                'items' => $order->items->map(function ($item) {
                    return [
                        'name' => $item->name ?? ($item->product?->name ?? 'Product'),
                        'qty' => (float) ($item->qty ?? $item->quantity ?? 1),
                        'price' => (float) $item->unit_price,
                        'lineTotal' => (float) ($item->subtotal ?? $item->line_total ?? 0),
                    ];
                })->toArray(),
                'totalAmount' => (float) $order->total_amount,
                'paymentSplits' => $order->paymentSplits->map(function ($split) {
                    return [
                        'method' => strtoupper($split->method ?? $split->payment_method ?? 'CASH'),
                        'amount' => (float) $split->amount,
                    ];
                })->toArray(),
            ];

            try {
                $response = Http::timeout(2)->post("{$this->baseUrl}/api/v1/receipt/escpos", $payload);

                if ($response->successful()) {
                    return $response->json('data', []);
                }
            } catch (Exception $e) {
                Log::warning('[NodeWorkerBridge] ESC/POS HTTP generation failed, using formatted text fallback: ' . $e->getMessage());
            }
        }

        // ASCII Thermal Receipt Fallback
        return [
            'orderNo' => $order->order_no,
            'hexCommands' => bin2hex($this->formatAsciiReceipt($order)),
            'bytesCount' => strlen($this->formatAsciiReceipt($order)),
            'fallback' => true,
        ];
    }

    /**
     * Publish print job event via Redis Pub/Sub for immediate daemon consumption.
     */
    public function publishPrintJob(array $payload): bool
    {
        try {
            Redis::publish('pos:print-job', json_encode($payload));

            return true;
        } catch (Exception $e) {
            Log::warning('[NodeWorkerBridge] Redis publish to pos:print-job failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Publish barcode generation event via Redis Pub/Sub.
     */
    public function publishBarcodeGen(array $payload): bool
    {
        try {
            Redis::publish('pos:barcode-gen', json_encode($payload));

            return true;
        } catch (Exception $e) {
            Log::warning('[NodeWorkerBridge] Redis publish to pos:barcode-gen failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Format a clean ASCII thermal receipt for local fallback & offline thermal printers.
     */
    public function formatAsciiReceipt(SalesOrder $order): string
    {
        $lines = [];
        $lines[] = '================================';
        $lines[] = '    DOT AUTO WORKSHOP & POS     ';
        $lines[] = '    Branch: Sanchaung, Yangon   ';
        $lines[] = '    Hotline: 09-778574747       ';
        $lines[] = '================================';
        $lines[] = "Receipt No: {$order->order_no}";
        $lines[] = 'Date: ' . ($order->created_at ? $order->created_at->format('Y-m-d H:i') : date('Y-m-d H:i'));
        $lines[] = 'Customer: ' . ($order->customer?->name ?? 'Walk-in Customer');
        if ($order->vehicle) {
            $lines[] = "Vehicle: {$order->vehicle->license_plate} ({$order->vehicle->model})";
        }
        $lines[] = '--------------------------------';
        $lines[] = sprintf('%-16s %3s %9s', 'ITEM', 'QTY', 'AMOUNT');
        $lines[] = '--------------------------------';

        foreach ($order->items as $item) {
            $name = substr($item->name ?? ($item->product?->name ?? 'Item'), 0, 16);
            $qty = (int) ($item->qty ?? $item->quantity ?? 1);
            $total = (float) ($item->subtotal ?? $item->line_total ?? 0);
            $lines[] = sprintf('%-16s %3d %9s', $name, $qty, number_format($total, 2));
        }

        $lines[] = '--------------------------------';
        $lines[] = sprintf('%-20s %10s', 'Subtotal:', number_format((float) $order->subtotal, 2));
        if ((float) $order->labor_total > 0) {
            $lines[] = sprintf('%-20s %10s', 'Labor Total:', number_format((float) $order->labor_total, 2));
        }
        if ((float) $order->discount_amount > 0) {
            $lines[] = sprintf('%-20s -%9s', 'Discount:', number_format((float) $order->discount_amount, 2));
        }
        $lines[] = sprintf('%-20s %10s', 'TOTAL:', number_format((float) $order->total_amount, 2));
        $lines[] = '--------------------------------';

        foreach ($order->paymentSplits as $split) {
            $lines[] = sprintf('Paid (%s): %14s', strtoupper($split->method ?? $split->payment_method ?? 'Cash'), number_format((float) $split->amount, 2));
        }

        if ((float) $order->due_amount > 0) {
            $lines[] = sprintf('%-20s %10s', 'DUE (Credit Debt):', number_format((float) $order->due_amount, 2));
        }

        $lines[] = '================================';
        $lines[] = '    Thank you for your visit!   ';
        $lines[] = "      Powered by DOT POS ERP    \n\n\n";

        return implode("\n", $lines);
    }
}

<?php

namespace App\Jobs;

use App\Services\NodeWorkerBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchThermalPrintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $printPayload;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(array $printPayload)
    {
        $this->printPayload = $printPayload;
        $this->onQueue('receipts');
    }

    /**
     * Execute the job.
     */
    public function handle(NodeWorkerBridgeService $bridge): void
    {
        Log::info('[DispatchThermalPrintJob] Publishing print payload to Node.js thermal daemon.');

        $published = $bridge->publishPrintJob($this->printPayload);

        if (! $published) {
            Log::warning('[DispatchThermalPrintJob] Redis publish failed, logging print payload.');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('[DispatchThermalPrintJob] Print dispatch failed: ' . ($exception ? $exception->getMessage() : 'Unknown error'));
    }
}

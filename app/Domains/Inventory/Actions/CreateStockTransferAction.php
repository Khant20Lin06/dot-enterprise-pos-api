<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\StockTransferItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Services\AtomicSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateStockTransferAction
{
    public function __construct(
        protected AtomicSequenceService $atomicSequenceService
    ) {}

    /**
     * Create a draft stock transfer order between two physical warehouses.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, int $requestedBy): StockTransfer
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            $sourceId = (int) ($data['source_warehouse_id'] ?? 0);
            $destinationId = (int) ($data['destination_warehouse_id'] ?? 0);
            $itemsData = $data['items'] ?? [];

            if ($sourceId === $destinationId) {
                throw new DomainException('Source warehouse and destination warehouse cannot be the same physical location.');
            }

            $source = Warehouse::where('id', $sourceId)->where('is_active', true)->firstOrFail();
            $destination = Warehouse::where('id', $destinationId)->where('is_active', true)->firstOrFail();

            if (empty($itemsData)) {
                throw new DomainException('Stock transfer must contain at least one item.');
            }

            $transferNo = $this->atomicSequenceService->getNextSequence('TRF');

            $transfer = StockTransfer::create([
                'transfer_no' => $transferNo,
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'requested_by' => $requestedBy,
                'status' => 'Draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemsData as $item) {
                $qty = (int) ($item['qty'] ?? $item['qty_requested'] ?? 1);
                if ($qty <= 0) {
                    throw new DomainException('Requested transfer quantity must be greater than zero.');
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => (int) $item['product_id'],
                    'product_variant_id' => isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                    'product_option_id' => isset($item['product_option_id']) ? (int) $item['product_option_id'] : null,
                    'qty_requested' => $qty,
                    'qty_dispatched' => 0,
                    'qty_received' => 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            AuditLog::create([
                'staff_id' => $requestedBy,
                'module' => 'inventory',
                'action' => 'stock_transfer_created',
                'severity' => 'info',
                'resource_type' => 'stock_transfers',
                'resource_id' => (string) $transfer->id,
                'description' => "Draft Transfer {$transferNo} created from '{$source->name}' to '{$destination->name}'.",
                'diffs' => [
                    'transfer_no' => $transferNo,
                    'source' => $source->name,
                    'destination' => $destination->name,
                    'items_count' => count($itemsData),
                ],
                'created_at' => now(),
            ]);

            return $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items.product', 'requester']);
        });
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Warehouse;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateWarehouseAction
{
    /**
     * Create a new physical branch or central warehouse.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, ?int $operatorId = null): Warehouse
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));

            if (empty($code)) {
                throw new DomainException('Warehouse code cannot be empty.');
            }

            if (Warehouse::where('code', $code)->exists()) {
                throw new DomainException("Warehouse with code '{$code}' already exists.");
            }

            // If marked central, unset any existing central warehouse
            if (! empty($data['is_central'])) {
                Warehouse::where('is_central', true)->update(['is_central' => false]);
            }

            $warehouse = Warehouse::create([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'type' => $data['type'] ?? 'Branch Counter',
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_central' => (bool) ($data['is_central'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'inventory',
                'action' => 'warehouse_created',
                'severity' => 'info',
                'resource_type' => 'warehouses',
                'resource_id' => (string) $warehouse->id,
                'description' => "Warehouse '{$warehouse->name}' ({$warehouse->code}) created.",
                'diffs' => [
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'type' => $warehouse->type,
                ],
                'created_at' => now(),
            ]);

            return $warehouse;
        });
    }
}

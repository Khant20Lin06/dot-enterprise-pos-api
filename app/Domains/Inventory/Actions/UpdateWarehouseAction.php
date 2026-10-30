<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Warehouse;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateWarehouseAction
{
    /**
     * Update warehouse details or active status.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(Warehouse $warehouse, array $data, ?int $operatorId = null): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data, $operatorId) {
            $prevDiff = [
                'name' => $warehouse->name,
                'type' => $warehouse->type,
                'is_central' => $warehouse->is_central,
                'is_active' => $warehouse->is_active,
            ];

            if (! empty($data['is_central']) && ! $warehouse->is_central) {
                Warehouse::where('id', '!=', $warehouse->id)->where('is_central', true)->update(['is_central' => false]);
            }

            $updatePayload = [];

            if (isset($data['name'])) {
                $updatePayload['name'] = trim((string) $data['name']);
            }

            if (isset($data['type'])) {
                $updatePayload['type'] = $data['type'];
            }

            if (isset($data['address'])) {
                $updatePayload['address'] = $data['address'];
            }

            if (isset($data['phone'])) {
                $updatePayload['phone'] = $data['phone'];
            }

            if (isset($data['is_central'])) {
                $updatePayload['is_central'] = (bool) $data['is_central'];
            }

            if (isset($data['is_active'])) {
                $updatePayload['is_active'] = (bool) $data['is_active'];
            }

            $warehouse->update($updatePayload);

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'inventory',
                'action' => 'warehouse_updated',
                'severity' => 'info',
                'resource_type' => 'warehouses',
                'resource_id' => (string) $warehouse->id,
                'description' => "Warehouse '{$warehouse->name}' ({$warehouse->code}) updated.",
                'diffs' => [
                    'before' => $prevDiff,
                    'after' => [
                        'name' => $warehouse->name,
                        'type' => $warehouse->type,
                        'is_central' => $warehouse->is_central,
                        'is_active' => $warehouse->is_active,
                    ],
                ],
                'created_at' => now(),
            ]);

            return $warehouse->fresh();
        });
    }
}

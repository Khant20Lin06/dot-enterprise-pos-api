<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVehicleFitment;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use InvalidArgumentException;

class AttachVehicleFitmentAction
{
    /**
     * Attach an automotive vehicle compatibility rule to a spare part product.
     *
     * @param  array<string, mixed>  $data
     * @throws InvalidArgumentException
     */
    public function execute(Product $product, array $data, ?int $operatorId = null): ProductVehicleFitment
    {
        $make = trim($data['make'] ?? '');
        $model = trim($data['model'] ?? '');
        $yearFrom = ! empty($data['year_from']) ? (int) $data['year_from'] : null;
        $yearTo = ! empty($data['year_to']) ? (int) $data['year_to'] : null;

        if (empty($make) || empty($model)) {
            throw new InvalidArgumentException('Vehicle Make and Model are required for compatibility mapping.');
        }

        if ($yearFrom && $yearTo && $yearFrom > $yearTo) {
            throw new InvalidArgumentException("Year From ({$yearFrom}) cannot be greater than Year To ({$yearTo}).");
        }

        $fitment = ProductVehicleFitment::create([
            'product_id' => $product->id,
            'make' => $make,
            'model' => $model,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'engine_code' => ! empty($data['engine_code']) ? trim($data['engine_code']) : null,
            'chassis_code' => ! empty($data['chassis_code']) ? trim($data['chassis_code']) : null,
            'position' => ! empty($data['position']) ? trim($data['position']) : null,
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::create([
            'staff_id' => $operatorId,
            'module' => 'inventory',
            'action' => 'vehicle_fitment_attached',
            'severity' => 'info',
            'resource_type' => 'product_vehicle_fitments',
            'resource_id' => (string) $fitment->id,
            'description' => "Mapped {$product->name} compatibility to {$make} {$model}" . ($yearFrom ? " ({$yearFrom}-" . ($yearTo ?? 'Now') . ")" : ''),
            'diffs' => [
                'product_id' => $product->id,
                'make' => $make,
                'model' => $model,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
            ],
            'created_at' => now(),
        ]);

        return $fitment->load('product');
    }
}

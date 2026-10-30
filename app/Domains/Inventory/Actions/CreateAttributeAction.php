<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Attribute;
use App\Domains\Inventory\Models\AttributeValue;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateAttributeAction
{
    /**
     * Create an attribute with predefined values (e.g. Size, Color).
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, ?int $operatorId = null): Attribute
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));

            if (empty($code)) {
                throw new DomainException('Attribute code cannot be empty.');
            }

            if (Attribute::where('code', $code)->exists()) {
                throw new DomainException("Attribute with code '{$code}' already exists.");
            }

            $attribute = Attribute::create([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
            ]);

            $values = $data['values'] ?? [];
            foreach ($values as $index => $val) {
                $valName = is_array($val) ? $val['value'] : $val;
                $colorHex = is_array($val) ? ($val['color_hex'] ?? null) : null;

                AttributeValue::create([
                    'attribute_id' => $attribute->id,
                    'value' => trim((string) $valName),
                    'color_hex' => $colorHex,
                    'sort_order' => $index,
                ]);
            }

            AuditLog::create([
                'staff_id' => $operatorId,
                'module' => 'inventory',
                'action' => 'attribute_created',
                'severity' => 'info',
                'resource_type' => 'attributes',
                'resource_id' => (string) $attribute->id,
                'description' => "Attribute '{$attribute->name}' ({$attribute->code}) created with " . count($values) . " values.",
                'diffs' => [
                    'code' => $attribute->code,
                    'name' => $attribute->name,
                ],
                'created_at' => now(),
            ]);

            return $attribute->fresh('values');
        });
    }
}

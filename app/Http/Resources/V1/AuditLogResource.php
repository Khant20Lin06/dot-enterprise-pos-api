<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
                'email' => $this->staff->email,
            ] : null,
            'module' => $this->module,
            'action' => $this->action,
            'severity' => $this->severity,
            'resourceType' => $this->resource_type,
            'resourceId' => $this->resource_id,
            'description' => $this->description,
            'diffs' => $this->diffs,
            'ipAddress' => $this->ip_address,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

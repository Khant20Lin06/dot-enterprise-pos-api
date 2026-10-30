<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'badgeColor' => $this->badge_color,
            'description' => $this->description,
            'isSystem' => (bool) $this->is_system,
            'permissions' => $this->permissions ?? [],
            'staffCount' => $this->whenCounted('staffUsers', $this->staff_users_count, $this->staffUsers()->count()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

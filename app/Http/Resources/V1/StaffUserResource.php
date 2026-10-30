<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => [
                'id' => $this->role?->id,
                'code' => $this->role?->code,
                'name' => $this->role?->name,
                'badgeColor' => $this->role?->badge_color,
                'permissions' => $this->role?->permissions ?? [],
            ],
            'status' => $this->status,
            'workstation' => $this->workstation,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

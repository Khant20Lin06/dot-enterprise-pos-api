<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'type' => $this->type,
            'value' => (float) $this->value,
            'minSpend' => (float) $this->min_spend,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'usageLimit' => $this->usage_limit,
            'timesUsed' => (int) $this->times_used,
            'isActive' => (bool) $this->is_active,
            'targetTier' => $this->target_tier,
            'description' => $this->description,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

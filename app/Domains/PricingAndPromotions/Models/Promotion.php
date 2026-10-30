<?php

namespace App\Domains\PricingAndPromotions\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'code',
        'title',
        'type',
        'value',
        'min_spend',
        'start_date',
        'end_date',
        'usage_limit',
        'times_used',
        'is_active',
        'target_tier',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'usage_limit' => 'integer',
        'times_used' => 'integer',
    ];

    public function isValidForSpend(float $subtotal, ?string $customerTier = null): bool
    {
        if (!$this->is_active) return false;
        
        $today = now()->toDateString();
        $startDate = $this->start_date ? \Illuminate\Support\Carbon::parse($this->start_date)->toDateString() : null;
        $endDate = $this->end_date ? \Illuminate\Support\Carbon::parse($this->end_date)->toDateString() : null;

        if (($startDate && $startDate > $today) || ($endDate && $endDate < $today)) {
            return false;
        }
        
        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) return false;
        
        if ($subtotal < (float) $this->min_spend) return false;
        
        if ($this->target_tier !== 'All' && $customerTier && $this->target_tier !== $customerTier) return false;

        return true;
    }
}

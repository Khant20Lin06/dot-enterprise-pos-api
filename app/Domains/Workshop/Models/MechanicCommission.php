<?php

namespace App\Domains\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MechanicCommission extends Model
{
    protected $fillable = [
        'mechanic_id',
        'work_order_id',
        'labor_amount',
        'commission_rate',
        'earned_amount',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'labor_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'earned_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class, 'mechanic_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}

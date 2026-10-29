<?php

namespace App\Domains\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mechanic extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'specialization',
        'commission_rate',
        'status',
        'avatar',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'assigned_mechanic_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MechanicCommission::class, 'mechanic_id');
    }

    public function pendingCommissionTotal(): float
    {
        return (float) $this->commissions()->where('status', 'Pending')->sum('earned_amount');
    }
}

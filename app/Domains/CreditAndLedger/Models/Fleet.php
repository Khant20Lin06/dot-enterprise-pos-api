<?php

namespace App\Domains\CreditAndLedger\Models;

use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fleet extends Model
{
    protected $fillable = [
        'name',
        'type',
        'credit_limit',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'fleet_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'fleet_id');
    }
}

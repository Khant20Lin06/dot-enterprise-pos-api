<?php

namespace App\Domains\Workshop\Models;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\SalesAndPos\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'plate_number',
        'customer_id',
        'fleet_id',
        'make',
        'model',
        'year',
        'vin',
        'engine_no',
        'color',
        'transmission',
        'fuel_type',
        'current_mileage',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'current_mileage' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'vehicle_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'vehicle_id');
    }
}

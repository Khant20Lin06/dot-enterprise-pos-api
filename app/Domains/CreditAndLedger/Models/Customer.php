<?php

namespace App\Domains\CreditAndLedger\Models;

use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'fleet_id',
        'tier',
        'price_list_id',
        'due_amount',
        'credit_limit',
        'loyalty_points',
        'notes',
        'last_service_date',
        'next_service_due_date',
    ];

    protected $casts = [
        'due_amount' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'loyalty_points' => 'integer',
        'last_service_date' => 'date',
        'next_service_due_date' => 'date',
    ];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'customer_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'customer_id');
    }

    public function creditSettlements(): HasMany
    {
        return $this->hasMany(CreditSettlement::class, 'customer_id');
    }
}

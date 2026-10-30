<?php

namespace App\Domains\Workshop\Models;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\SalesAndPos\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $fillable = [
        'job_card_no',
        'customer_id',
        'vehicle_id',
        'fleet_id',
        'assigned_mechanic_id',
        'bay',
        'priority',
        'status',
        'complaint',
        'mileage_in',
        'labor_fee',
        'parts_total',
        'total_amount',
        'sales_order_id',
        'notes',
    ];

    protected $casts = [
        'mileage_in' => 'integer',
        'labor_fee' => 'decimal:2',
        'parts_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class, 'assigned_mechanic_id');
    }

    public function primaryMechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class, 'assigned_mechanic_id');
    }

    public function serviceAdvisor(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\SecurityAndAudit\Models\StaffUser::class, 'service_advisor_id');
    }

    public function getLaborTotalAttribute()
    {
        return $this->attributes['labor_fee'] ?? 0;
    }

    public function getGrandTotalAttribute()
    {
        return $this->attributes['total_amount'] ?? (($this->attributes['parts_total'] ?? 0) + ($this->attributes['labor_fee'] ?? 0));
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id');
    }

    public function outsourcedParts(): HasMany
    {
        return $this->hasMany(OutsourcedPart::class, 'work_order_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MechanicCommission::class, 'work_order_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}

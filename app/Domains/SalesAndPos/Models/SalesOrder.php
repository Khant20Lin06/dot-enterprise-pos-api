<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_no',
        'customer_id',
        'vehicle_id',
        'cashier_id',
        'subtotal',
        'labor_total',
        'discount_amount',
        'discount_note',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'due_amount',
        'points_earned',
        'points_redeemed',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'labor_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'points_earned' => 'integer',
        'points_redeemed' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentSplit::class, 'sales_order_id');
    }

    public function paymentSplits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class, 'sales_order_id');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class, 'sales_order_id');
    }
}

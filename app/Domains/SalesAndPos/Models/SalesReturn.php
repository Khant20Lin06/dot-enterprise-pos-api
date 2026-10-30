<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_no',
        'sales_order_id',
        'customer_id',
        'cashier_id',
        'pos_session_id',
        'total_refund_amount',
        'refund_method',
        'refund_reference',
        'reason',
        'status',
        'notes',
        'points_deducted',
        'idempotency_key',
    ];

    protected $casts = [
        'total_refund_amount' => 'decimal:2',
        'points_deducted' => 'integer',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cashier_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }
}

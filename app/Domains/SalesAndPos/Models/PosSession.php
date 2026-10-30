<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\Inventory\Models\Warehouse;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    protected $fillable = [
        'session_no',
        'cashier_id',
        'terminal_id',
        'warehouse_id',
        'opening_float',
        'status',
        'opened_at',
        'closed_at',
        'counted_cash',
        'expected_cash',
        'cash_variance',
        'total_sales',
        'total_refunds',
        'cash_sales',
        'kpay_sales',
        'wave_sales',
        'card_sales',
        'credit_sales',
        'opening_notes',
        'closing_notes',
    ];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_refunds' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'kpay_sales' => 'decimal:2',
        'wave_sales' => 'decimal:2',
        'card_sales' => 'decimal:2',
        'credit_sales' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cashier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'pos_session_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'pos_session_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'pos_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}

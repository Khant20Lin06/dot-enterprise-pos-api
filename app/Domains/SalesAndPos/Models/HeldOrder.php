<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeldOrder extends Model
{
    protected $fillable = [
        'hold_id',
        'title',
        'customer_id',
        'cashier_id',
        'pos_session_id',
        'cart_data',
        'subtotal',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'subtotal' => 'decimal:2',
    ];

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cashier_id');
    }
}

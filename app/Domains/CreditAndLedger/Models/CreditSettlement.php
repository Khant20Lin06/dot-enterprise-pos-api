<?php

namespace App\Domains\CreditAndLedger\Models;

use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditSettlement extends Model
{
    protected $fillable = [
        'receipt_no',
        'customer_id',
        'amount',
        'payment_method',
        'note',
        'cashier_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cashier_id');
    }
}

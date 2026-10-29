<?php

namespace App\Domains\SalesAndPos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSplit extends Model
{
    protected $fillable = [
        'sales_order_id',
        'method',
        'amount',
        'reference_no',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}

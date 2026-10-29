<?php

namespace App\Domains\Workshop\Models;

use App\Domains\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutsourcedPart extends Model
{
    protected $fillable = [
        'work_order_id',
        'supplier_id',
        'part_name',
        'cost_price',
        'bill_price',
        'status',
        'payment_status',
        'notes',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'bill_price' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function profit(): float
    {
        return (float) ($this->bill_price - $this->cost_price);
    }
}

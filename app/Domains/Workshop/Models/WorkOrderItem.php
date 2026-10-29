<?php

namespace App\Domains\Workshop\Models;

use App\Domains\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id',
        'product_id',
        'name',
        'qty',
        'unit_price',
        'cost_price',
        'subtotal',
        'is_outsourced',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'is_outsourced' => 'boolean',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

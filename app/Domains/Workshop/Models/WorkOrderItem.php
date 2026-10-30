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
        'uom_conversion_id',
        'uom_id',
        'conversion_factor',
        'name',
        'qty',
        'unit_price',
        'cost_price',
        'subtotal',
        'is_outsourced',
    ];

    protected $casts = [
        'qty' => 'integer',
        'conversion_factor' => 'decimal:4',
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

    public function uomConversion(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\ProductUomConversion::class, 'uom_conversion_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\UnitOfMeasure::class, 'uom_id');
    }
}

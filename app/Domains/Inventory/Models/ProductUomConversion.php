<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUomConversion extends Model
{
    protected $fillable = [
        'product_id',
        'uom_id',
        'unit_name',
        'conversion_factor',
        'barcode',
        'sku',
        'cost_price',
        'sell_price',
        'is_purchase_default',
        'is_sale_default',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:4',
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'is_purchase_default' => 'boolean',
        'is_sale_default' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}

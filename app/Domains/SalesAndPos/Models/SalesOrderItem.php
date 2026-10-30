<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id',
        'product_id',
        'product_option_id',
        'product_variant_id',
        'uom_conversion_id',
        'uom_id',
        'conversion_factor',
        'packaging_qty',
        'name',
        'qty',
        'unit_price',
        'cost_price',
        'discount_amount',
        'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
        'conversion_factor' => 'decimal:4',
        'packaging_qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\ProductVariant::class, 'product_variant_id');
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

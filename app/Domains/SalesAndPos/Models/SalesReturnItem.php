<?php

namespace App\Domains\SalesAndPos\Models;

use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    protected $fillable = [
        'sales_return_id',
        'sales_order_item_id',
        'product_id',
        'product_option_id',
        'product_variant_id',
        'qty',
        'unit_price',
        'refund_subtotal',
        'item_condition',
        'restocked',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'refund_subtotal' => 'decimal:2',
        'restocked' => 'boolean',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\ProductVariant::class, 'product_variant_id');
    }
}

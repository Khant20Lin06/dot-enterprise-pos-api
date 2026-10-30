<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'product_option_id',
        'stock_qty',
        'min_alert_qty',
        'bin_location',
    ];

    protected $casts = [
        'stock_qty' => 'integer',
        'min_alert_qty' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}

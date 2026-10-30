<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'product_option_id',
        'qty_requested',
        'qty_dispatched',
        'qty_received',
        'notes',
    ];

    protected $casts = [
        'qty_requested' => 'integer',
        'qty_dispatched' => 'integer',
        'qty_received' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
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

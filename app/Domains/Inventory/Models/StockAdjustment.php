<?php

namespace App\Domains\Inventory\Models;

use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'product_id',
        'type',
        'prev_stock',
        'adjusted_qty',
        'new_stock',
        'reason',
        'staff_id',
    ];

    protected $casts = [
        'prev_stock' => 'integer',
        'adjusted_qty' => 'integer',
        'new_stock' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_id');
    }
}

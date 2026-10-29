<?php

namespace App\Domains\Purchasing\Models;

use App\Domains\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'return_no',
        'purchase_id',
        'supplier_id',
        'product_id',
        'qty',
        'amount',
        'reason',
        'status',
    ];

    protected $casts = [
        'qty' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

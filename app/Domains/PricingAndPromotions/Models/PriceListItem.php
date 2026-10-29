<?php

namespace App\Domains\PricingAndPromotions\Models;

use App\Domains\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id',
        'product_id',
        'min_qty',
        'pricing_method',
        'value',
    ];

    protected $casts = [
        'min_qty' => 'integer',
        'value' => 'decimal:2',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

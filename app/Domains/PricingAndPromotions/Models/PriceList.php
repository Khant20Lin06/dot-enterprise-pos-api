<?php

namespace App\Domains\PricingAndPromotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'currency',
        'is_default',
        'is_active',
        'start_date',
        'end_date',
        'description',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'price_list_id');
    }
}

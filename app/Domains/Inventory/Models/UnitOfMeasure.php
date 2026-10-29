<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = [
        'name',
        'description',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'uom_id');
    }
}

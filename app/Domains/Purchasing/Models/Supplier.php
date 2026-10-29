<?php

namespace App\Domains\Purchasing\Models;

use App\Domains\Inventory\Models\Product;
use App\Domains\Workshop\Models\OutsourcedPart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'contact_person',
        'address',
        'outstanding_balance',
    ];

    protected $casts = [
        'outstanding_balance' => 'decimal:2',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    public function outsourcedParts(): HasMany
    {
        return $this->hasMany(OutsourcedPart::class, 'supplier_id');
    }
}

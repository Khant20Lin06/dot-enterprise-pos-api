<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'address',
        'phone',
        'is_central',
        'is_active',
    ];

    protected $casts = [
        'is_central' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'warehouse_id');
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'source_warehouse_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'destination_warehouse_id');
    }
}

<?php

namespace App\Domains\Inventory\Models;

use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_no',
        'source_warehouse_id',
        'destination_warehouse_id',
        'requested_by',
        'approved_by',
        'received_by',
        'status',
        'notes',
        'dispatched_at',
        'received_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }
}

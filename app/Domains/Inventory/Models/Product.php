<?php

namespace App\Domains\Inventory\Models;

use App\Domains\PricingAndPromotions\Models\PriceListItem;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SalesAndPos\Models\SalesOrderItem;
use App\Domains\Workshop\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'uom_id',
        'supplier_id',
        'name',
        'barcode',
        'oem_part_no',
        'car_fitment',
        'bin_location',
        'cost_price',
        'sell_price',
        'wholesale_price',
        'stock',
        'min_stock',
        'image_url',
        'is_labor',
        'is_outsourced',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'stock' => 'integer',
        'min_stock' => 'integer',
        'is_labor' => 'boolean',
        'is_outsourced' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function getRetailPriceAttribute()
    {
        return $this->attributes['retail_price'] ?? $this->attributes['sell_price'] ?? 0;
    }

    public function getMinStockAlertAttribute()
    {
        return $this->attributes['min_stock_alert'] ?? $this->attributes['min_stock'] ?? 0;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class, 'product_id');
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'product_id');
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'product_id');
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'product_id');
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'product_id');
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }

    public function isLowStock(): bool
    {
        return !$this->is_labor && $this->stock <= $this->min_stock;
    }
}

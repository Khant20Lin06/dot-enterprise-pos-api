<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVehicleFitment extends Model
{
    protected $fillable = [
        'product_id',
        'make',
        'model',
        'year_from',
        'year_to',
        'engine_code',
        'chassis_code',
        'position',
        'notes',
    ];

    protected $casts = [
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Check if this fitment rule matches a specific Vehicle entity.
     */
    public function matchesVehicle(Vehicle $vehicle): bool
    {
        if (strcasecmp($this->make, $vehicle->make) !== 0) {
            return false;
        }

        // Fuzzy or exact model match
        if (stripos($vehicle->model, $this->model) === false && stripos($this->model, $vehicle->model) === false) {
            return false;
        }

        if ($vehicle->year) {
            if ($this->year_from && $vehicle->year < $this->year_from) {
                return false;
            }
            if ($this->year_to && $vehicle->year > $this->year_to) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Product;
use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

class SearchVehicleCompatiblePartsAction
{
    /**
     * Search catalog products compatible with a specific vehicle or make/model/year criteria.
     *
     * @param  array<string, mixed>|Vehicle  $criteria
     * @return Collection<int, Product>
     */
    public function execute(array|Vehicle $criteria): Collection
    {
        if ($criteria instanceof Vehicle) {
            $make = $criteria->make;
            $model = $criteria->model;
            $year = $criteria->year;
            $engineNo = $criteria->engine_no;
        } else {
            $make = $criteria['make'] ?? null;
            $model = $criteria['model'] ?? null;
            $year = ! empty($criteria['year']) ? (int) $criteria['year'] : null;
            $engineNo = $criteria['engine_code'] ?? null;
        }

        $query = Product::with(['category', 'uom', 'uomConversions.uom', 'variants', 'vehicleFitments'])
            ->where('is_labor', false);

        $query->whereHas('vehicleFitments', function ($q) use ($make, $model, $year) {
            if ($make) {
                $q->where('make', 'like', "%{$make}%");
            }
            if ($model) {
                $q->where(function ($sub) use ($model) {
                    $sub->where('model', 'like', "%{$model}%");

                    // Match individual keywords (e.g. "Fit GP5" matches fitment "Fit")
                    $keywords = array_filter(explode(' ', trim($model)), fn ($w) => strlen($w) >= 2);
                    foreach ($keywords as $kw) {
                        $sub->orWhere('model', 'like', "%{$kw}%");
                    }
                });
            }
            if ($year) {
                $q->where(function ($sub) use ($year) {
                    $sub->whereNull('year_from')->orWhere('year_from', '<=', $year);
                })->where(function ($sub) use ($year) {
                    $sub->whereNull('year_to')->orWhere('year_to', '>=', $year);
                });
            }
        });

        return $query->get();
    }
}

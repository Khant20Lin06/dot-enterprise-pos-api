<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateUnitOfMeasureRequest;
use App\Http\Requests\V1\UpdateUnitOfMeasureRequest;
use App\Http\Resources\V1\UnitOfMeasureResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitOfMeasureController extends Controller
{
    /**
     * List all units of measure.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = UnitOfMeasure::withCount('products');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $units = $query->orderBy('name')->get();

        return UnitOfMeasureResource::collection($units);
    }

    /**
     * Create a new unit of measure.
     */
    public function store(CreateUnitOfMeasureRequest $request): JsonResponse
    {
        $unit = UnitOfMeasure::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Unit of Measure created successfully.',
            'data' => new UnitOfMeasureResource($unit),
        ], 201);
    }

    /**
     * Show single unit of measure details.
     */
    public function show(int $id): JsonResponse
    {
        $unit = UnitOfMeasure::withCount('products')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new UnitOfMeasureResource($unit),
        ]);
    }

    /**
     * Update unit of measure.
     */
    public function update(UpdateUnitOfMeasureRequest $request, int $id): JsonResponse
    {
        $unit = UnitOfMeasure::findOrFail($id);
        $unit->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Unit of Measure updated successfully.',
            'data' => new UnitOfMeasureResource($unit),
        ]);
    }

    /**
     * Delete unit of measure (guarded against active products).
     */
    public function destroy(int $id): JsonResponse
    {
        $unit = UnitOfMeasure::findOrFail($id);

        if ($unit->products()->exists() || $unit->uomConversions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete Unit of Measure '{$unit->name}'. It is currently assigned to products or packaging conversions.",
            ], 422);
        }

        $unit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Unit of Measure deleted successfully.',
        ]);
    }
}

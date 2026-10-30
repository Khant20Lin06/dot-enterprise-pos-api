<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\AttachVehicleFitmentAction;
use App\Domains\Inventory\Actions\SearchVehicleCompatiblePartsAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVehicleFitment;
use App\Domains\Workshop\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AttachVehicleFitmentRequest;
use App\Http\Requests\V1\UpdateVehicleFitmentRequest;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ProductVehicleFitmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleFitmentController extends Controller
{
    /**
     * List all vehicle fitments mapped to a product.
     */
    public function index(int $productId): JsonResponse
    {
        $product = Product::with('vehicleFitments')->findOrFail($productId);

        return response()->json([
            'success' => true,
            'data' => ProductVehicleFitmentResource::collection($product->vehicleFitments),
        ]);
    }

    /**
     * Attach a vehicle compatibility mapping to a product.
     */
    public function store(
        int $productId,
        AttachVehicleFitmentRequest $request,
        AttachVehicleFitmentAction $action
    ): JsonResponse {
        $product = Product::findOrFail($productId);
        $operatorId = $request->user()?->id;

        $fitment = $action->execute($product, $request->validated(), $operatorId);

        return response()->json([
            'success' => true,
            'message' => 'Vehicle compatibility mapped successfully.',
            'data' => new ProductVehicleFitmentResource($fitment),
        ], 201);
    }

    /**
     * Update an existing vehicle fitment mapping rule.
     */
    public function update(
        int $productId,
        int $fitmentId,
        UpdateVehicleFitmentRequest $request
    ): JsonResponse {
        $fitment = ProductVehicleFitment::where('product_id', $productId)
            ->where('id', $fitmentId)
            ->firstOrFail();

        $fitment->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Vehicle compatibility mapping updated successfully.',
            'data' => new ProductVehicleFitmentResource($fitment),
        ]);
    }

    /**
     * Remove a vehicle fitment mapping rule.
     */
    public function destroy(int $productId, int $fitmentId): JsonResponse
    {
        $fitment = ProductVehicleFitment::where('product_id', $productId)
            ->where('id', $fitmentId)
            ->firstOrFail();

        $fitment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vehicle fitment rule deleted.',
        ]);
    }

    /**
     * Find compatible spare parts for a registered customer vehicle.
     */
    public function compatibleParts(int $vehicleId, SearchVehicleCompatiblePartsAction $action): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($vehicleId);
        $parts = $action->execute($vehicle);

        return response()->json([
            'success' => true,
            'vehicle' => [
                'id' => (string) $vehicle->id,
                'plateNumber' => $vehicle->plate_number,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
            ],
            'totalCompatibleParts' => $parts->count(),
            'data' => ProductResource::collection($parts),
        ]);
    }

    /**
     * Global catalog compatibility search by query params (make, model, year).
     */
    public function search(Request $request, SearchVehicleCompatiblePartsAction $action): JsonResponse
    {
        $criteria = [
            'make' => $request->query('make'),
            'model' => $request->query('model'),
            'year' => $request->query('year'),
            'engine_code' => $request->query('engine_code'),
        ];

        $parts = $action->execute($criteria);

        return response()->json([
            'success' => true,
            'criteria' => $criteria,
            'total' => $parts->count(),
            'data' => ProductResource::collection($parts),
        ]);
    }
}

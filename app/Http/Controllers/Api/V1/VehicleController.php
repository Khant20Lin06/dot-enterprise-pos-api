<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Workshop\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateVehicleRequest;
use App\Http\Requests\V1\UpdateVehicleRequest;
use App\Http\Resources\V1\VehicleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
    /**
     * List vehicles with search, customer, and fleet filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Vehicle::with(['customer', 'fleet']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('plate_number', 'like', "%{$search}%")
                  ->orWhere('make', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhere('vin', 'like', "%{$search}%");
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('fleet_id')) {
            $query->where('fleet_id', $request->query('fleet_id'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $vehicles = $query->latest('id')->paginate($perPage);

        return VehicleResource::collection($vehicles);
    }

    /**
     * Register a new vehicle.
     */
    public function store(CreateVehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Vehicle registered successfully.',
            'data' => new VehicleResource($vehicle->load(['customer', 'fleet'])),
        ], 201);
    }

    /**
     * Show single vehicle details.
     */
    public function show(int $id): JsonResponse
    {
        $vehicle = Vehicle::with(['customer', 'fleet'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new VehicleResource($vehicle),
        ]);
    }

    /**
     * Update vehicle specifications or ownership.
     */
    public function update(UpdateVehicleRequest $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Vehicle updated successfully.',
            'data' => new VehicleResource($vehicle->fresh(['customer', 'fleet'])),
        ]);
    }

    /**
     * Delete vehicle (guarded against active workshop orders).
     */
    public function destroy(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        if ($vehicle->workOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete vehicle #{$id} with existing workshop Job Cards.",
            ], 422);
        }

        if ($vehicle->salesOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete vehicle #{$id} with historical sales orders.",
            ], 422);
        }

        $vehicle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vehicle deleted successfully.',
        ]);
    }
}

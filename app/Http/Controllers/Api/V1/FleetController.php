<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\CreditAndLedger\Models\Fleet;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateFleetRequest;
use App\Http\Requests\V1\UpdateFleetRequest;
use App\Http\Resources\V1\FleetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FleetController extends Controller
{
    /**
     * List corporate and logistics vehicle fleets.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Fleet::withCount(['customers', 'vehicles']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('type', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $fleets = $query->orderBy('name')->paginate($perPage);

        return FleetResource::collection($fleets);
    }

    /**
     * Register a new corporate fleet.
     */
    public function store(CreateFleetRequest $request): JsonResponse
    {
        $fleet = Fleet::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Fleet '{$fleet->name}' registered successfully.",
            'data' => new FleetResource($fleet->loadCount(['customers', 'vehicles'])),
        ], 201);
    }

    /**
     * Show single fleet details with associated vehicles and corporate customers.
     */
    public function show(int $id): JsonResponse
    {
        $fleet = Fleet::withCount(['customers', 'vehicles'])
            ->with(['customers', 'vehicles'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new FleetResource($fleet),
        ]);
    }

    /**
     * Update fleet account details.
     */
    public function update(UpdateFleetRequest $request, int $id): JsonResponse
    {
        $fleet = Fleet::findOrFail($id);
        $fleet->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Fleet '{$fleet->name}' updated successfully.",
            'data' => new FleetResource($fleet->fresh()->loadCount(['customers', 'vehicles'])),
        ]);
    }

    /**
     * Delete fleet with relational integrity guards.
     */
    public function destroy(int $id): JsonResponse
    {
        $fleet = Fleet::withCount(['customers', 'vehicles'])->findOrFail($id);

        if ($fleet->customers_count > 0 || $fleet->vehicles_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Integrity Error: Cannot delete fleet '{$fleet->name}' because it has {$fleet->vehicles_count} vehicles and {$fleet->customers_count} linked customers.",
            ], 422);
        }

        $fleet->delete();

        return response()->json([
            'success' => true,
            'message' => "Fleet '{$fleet->name}' deleted successfully.",
        ]);
    }
}

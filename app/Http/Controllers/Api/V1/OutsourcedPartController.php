<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Workshop\Models\OutsourcedPart;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateOutsourcedPartRequest;
use App\Http\Requests\V1\UpdateOutsourcedPartRequest;
use App\Http\Resources\V1\OutsourcedPartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutsourcedPartController extends Controller
{
    /**
     * List outsourced machining jobs and third-party parts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = OutsourcedPart::with(['supplier', 'workOrder']);

        if ($request->filled('work_order_id')) {
            $query->where('work_order_id', $request->query('work_order_id'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->query('payment_status'));
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $parts = $query->latest('id')->paginate($perPage);

        return OutsourcedPartResource::collection($parts);
    }

    /**
     * Order or register third-party parts for a job card.
     */
    public function store(CreateOutsourcedPartRequest $request): JsonResponse
    {
        $part = OutsourcedPart::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Outsourced part '{$part->part_name}' ordered successfully.",
            'data' => new OutsourcedPartResource($part->load(['supplier', 'workOrder'])),
        ], 201);
    }

    /**
     * Show single outsourced part details.
     */
    public function show(int $id): JsonResponse
    {
        $part = OutsourcedPart::with(['supplier', 'workOrder'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new OutsourcedPartResource($part),
        ]);
    }

    /**
     * Update outsourced part delivery or payment status.
     */
    public function update(UpdateOutsourcedPartRequest $request, int $id): JsonResponse
    {
        $part = OutsourcedPart::findOrFail($id);
        $part->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Outsourced part '{$part->part_name}' updated successfully.",
            'data' => new OutsourcedPartResource($part->fresh(['supplier', 'workOrder'])),
        ]);
    }

    /**
     * Delete an outsourced part record (guarded against invoiced job cards and fitted parts).
     */
    public function destroy(int $id): JsonResponse
    {
        $part = OutsourcedPart::with('workOrder')->findOrFail($id);

        if ($part->workOrder && in_array($part->workOrder->status, ['Completed / Invoiced', 'QC & Ready'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete outsourced part '{$part->part_name}' because Work Order #{$part->workOrder->job_card_no} has reached '{$part->workOrder->status}'. Billing and cost integrity must be preserved.",
            ], 422);
        }

        if ($part->status === 'Received & Fitted') {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete outsourced part '{$part->part_name}' because it has already been received and fitted to the vehicle.",
            ], 422);
        }

        $part->delete();

        return response()->json([
            'success' => true,
            'message' => "Outsourced part '{$part->part_name}' removed.",
        ]);
    }
}

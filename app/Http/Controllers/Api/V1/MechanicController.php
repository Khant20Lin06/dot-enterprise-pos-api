<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateMechanicRequest;
use App\Http\Requests\V1\PayoutCommissionRequest;
use App\Http\Requests\V1\UpdateMechanicRequest;
use App\Http\Resources\V1\MechanicCommissionResource;
use App\Http\Resources\V1\MechanicResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MechanicController extends Controller
{
    /**
     * List workshop mechanics with active jobs and commission totals.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Mechanic::query();

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('specialization', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $mechanics = $query->orderBy('name')->paginate($perPage);

        return MechanicResource::collection($mechanics);
    }

    /**
     * Register a new workshop technician.
     */
    public function store(CreateMechanicRequest $request): JsonResponse
    {
        $mechanic = Mechanic::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Mechanic '{$mechanic->name}' registered successfully.",
            'data' => new MechanicResource($mechanic),
        ], 201);
    }

    /**
     * Show single mechanic profile and commission balance.
     */
    public function show(int $id): JsonResponse
    {
        $mechanic = Mechanic::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new MechanicResource($mechanic),
        ]);
    }

    /**
     * Update mechanic profile, commission rate, or status.
     */
    public function update(UpdateMechanicRequest $request, int $id): JsonResponse
    {
        $mechanic = Mechanic::findOrFail($id);
        $mechanic->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => "Mechanic '{$mechanic->name}' updated successfully.",
            'data' => new MechanicResource($mechanic->fresh()),
        ]);
    }

    /**
     * Deactivate or delete mechanic with active assignment protection.
     */
    public function destroy(int $id): JsonResponse
    {
        $mechanic = Mechanic::findOrFail($id);

        $activeWorkOrders = $mechanic->workOrders()
            ->whereNotIn('status', ['Completed / Invoiced', 'Cancelled'])
            ->count();

        if ($activeWorkOrders > 0) {
            return response()->json([
                'success' => false,
                'message' => "Integrity Error: Cannot delete mechanic '{$mechanic->name}' because they are assigned to {$activeWorkOrders} active Job Cards.",
            ], 422);
        }

        if ($mechanic->commissions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete mechanic '{$mechanic->name}'. Historical commission and payroll records exist. Retain record for accounting and tax audit integrity.",
            ], 422);
        }

        $mechanic->delete();

        return response()->json([
            'success' => true,
            'message' => "Mechanic '{$mechanic->name}' removed successfully.",
        ]);
    }

    /**
     * List earned commissions for this mechanic.
     */
    public function commissions(Request $request, int $id): AnonymousResourceCollection
    {
        $mechanic = Mechanic::findOrFail($id);
        $query = $mechanic->commissions()->with('workOrder');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $commissions = $query->latest('id')->paginate(50);

        return MechanicCommissionResource::collection($commissions);
    }

    /**
     * Settle / Payout earned labor commissions to mechanic.
     */
    public function payoutCommissions(PayoutCommissionRequest $request, int $id): JsonResponse
    {
        $mechanic = Mechanic::findOrFail($id);
        $validated = $request->validated();

        $result = DB::transaction(function () use ($mechanic, $validated, $request) {
            $query = $mechanic->commissions()->where('status', 'Pending')->lockForUpdate();

            if (! empty($validated['commission_ids'])) {
                $query->whereIn('id', $validated['commission_ids']);
            }

            $pendingCommissions = $query->get();

            if ($pendingCommissions->isEmpty()) {
                throw new \DomainException("No pending commissions available to settle for mechanic '{$mechanic->name}'.");
            }

            $totalPaid = (float) $pendingCommissions->sum('earned_amount');
            $ids = $pendingCommissions->pluck('id')->toArray();

            MechanicCommission::whereIn('id', $ids)->update([
                'status' => 'Paid',
                'paid_at' => now(),
            ]);

            AuditLog::create([
                'staff_id' => $request->user()?->id,
                'module' => 'workshop',
                'action' => 'commissions_paid',
                'severity' => 'info',
                'resource_type' => 'mechanics',
                'resource_id' => (string) $mechanic->id,
                'description' => "Paid {$totalPaid} Ks in commissions ({$pendingCommissions->count()} records) to mechanic '{$mechanic->name}'",
                'diffs' => [
                    'mechanic_id' => $mechanic->id,
                    'total_paid' => $totalPaid,
                    'records_count' => $pendingCommissions->count(),
                ],
                'created_at' => now(),
            ]);

            return [
                'total_paid' => $totalPaid,
                'count' => $pendingCommissions->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => "Successfully disbursed {$result['total_paid']} Ks ({$result['count']} commissions) to {$mechanic->name}.",
            'data' => [
                'mechanic' => new MechanicResource($mechanic->fresh()),
                'total_disbursed' => $result['total_paid'],
                'commissions_count' => $result['count'],
            ],
        ]);
    }
}

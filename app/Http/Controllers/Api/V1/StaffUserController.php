<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SecurityAndAudit\Actions\CreateStaffUserAction;
use App\Domains\SecurityAndAudit\Actions\DeleteStaffUserAction;
use App\Domains\SecurityAndAudit\Actions\UpdateStaffStatusAction;
use App\Domains\SecurityAndAudit\Actions\UpdateStaffUserAction;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateStaffUserRequest;
use App\Http\Requests\V1\UpdateStaffStatusRequest;
use App\Http\Requests\V1\UpdateStaffUserRequest;
use App\Http\Resources\V1\StaffUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffUserController extends Controller
{
    /**
     * List all staff users with role and workstation details.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StaffUser::with('role');

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->query('role_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('workstation', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $staff = $query->latest('id')->paginate($perPage);

        return StaffUserResource::collection($staff);
    }

    /**
     * Register a new staff user with PIN and credentials.
     */
    public function store(CreateStaffUserRequest $request, CreateStaffUserAction $action): JsonResponse
    {
        $staff = $action->execute($request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Staff member '{$staff->name}' registered successfully.",
            'data' => new StaffUserResource($staff),
        ], 201);
    }

    /**
     * View details of a specific staff user.
     */
    public function show(int $id): JsonResponse
    {
        $staff = StaffUser::with('role')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new StaffUserResource($staff),
        ]);
    }

    /**
     * Update staff member details, PIN, password, or workstation.
     */
    public function update(UpdateStaffUserRequest $request, int $id, UpdateStaffUserAction $action): JsonResponse
    {
        $staff = StaffUser::findOrFail($id);
        $updated = $action->execute($staff, $request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Staff member '{$updated->name}' updated successfully.",
            'data' => new StaffUserResource($updated),
        ]);
    }

    /**
     * Update staff status (Active, Suspended, On Leave).
     */
    public function updateStatus(UpdateStaffStatusRequest $request, int $id, UpdateStaffStatusAction $action): JsonResponse
    {
        $staff = StaffUser::findOrFail($id);
        $updated = $action->execute($staff, $request->input('status'), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Staff member '{$updated->name}' status changed to '{$updated->status}'.",
            'data' => new StaffUserResource($updated),
        ]);
    }

    /**
     * Delete staff account.
     */
    public function destroy(int $id, DeleteStaffUserAction $action, Request $request): JsonResponse
    {
        $staff = StaffUser::findOrFail($id);
        $name = $staff->name;

        $action->execute($staff, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Staff member '{$name}' deleted successfully.",
        ]);
    }
}

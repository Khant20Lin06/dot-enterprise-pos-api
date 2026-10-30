<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SecurityAndAudit\Actions\CreateRoleAction;
use App\Domains\SecurityAndAudit\Actions\DeleteRoleAction;
use App\Domains\SecurityAndAudit\Actions\UpdateRoleAction;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateRoleRequest;
use App\Http\Requests\V1\UpdateRoleRequest;
use App\Http\Resources\V1\RoleResource;
use App\Services\PermissionCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    /**
     * List all enterprise roles with staff allocation counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::withCount('staffUsers')->orderBy('id')->get();

        return RoleResource::collection($roles);
    }

    /**
     * Expose complete canonical permissions catalog matching Next.js UI structure.
     */
    public function permissionsCatalog(PermissionCatalogService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'catalog' => $service->getCatalog(),
            'emptyTemplate' => $service->getEmptyTemplate(),
        ]);
    }

    /**
     * Create a new custom role.
     */
    public function store(CreateRoleRequest $request, CreateRoleAction $action): JsonResponse
    {
        $role = $action->execute($request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Role '{$role->name}' created successfully.",
            'data' => new RoleResource($role),
        ], 201);
    }

    /**
     * View details of a specific role.
     */
    public function show(int $id): JsonResponse
    {
        $role = Role::withCount('staffUsers')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new RoleResource($role),
        ]);
    }

    /**
     * Update existing role name, color or permission matrix.
     */
    public function update(UpdateRoleRequest $request, int $id, UpdateRoleAction $action): JsonResponse
    {
        $role = Role::findOrFail($id);
        $updated = $action->execute($role, $request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Role '{$updated->name}' updated successfully.",
            'data' => new RoleResource($updated),
        ]);
    }

    /**
     * Delete custom role.
     */
    public function destroy(int $id, DeleteRoleAction $action, Request $request): JsonResponse
    {
        $role = Role::findOrFail($id);
        $roleName = $role->name;

        $action->execute($role, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Role '{$roleName}' deleted successfully.",
        ]);
    }
}

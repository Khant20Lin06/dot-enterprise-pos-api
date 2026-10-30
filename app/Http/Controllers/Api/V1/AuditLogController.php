<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    /**
     * List audit logs with multi-field filtering and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::with('staff');

        if ($request->filled('module')) {
            $query->where('module', $request->query('module'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->query('staff_id'));
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->query('resource_type'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('description', 'like', "%{$search}%");
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $logs = $query->latest('id')->paginate($perPage);

        return AuditLogResource::collection($logs);
    }

    /**
     * Show single detailed audit log record.
     */
    public function show(int $id): JsonResponse
    {
        $log = AuditLog::with('staff')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new AuditLogResource($log),
        ]);
    }
}

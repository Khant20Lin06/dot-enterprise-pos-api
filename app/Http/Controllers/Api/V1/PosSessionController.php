<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SalesAndPos\Actions\ClosePosSessionAction;
use App\Domains\SalesAndPos\Actions\GenerateShiftAuditReportAction;
use App\Domains\SalesAndPos\Actions\OpenPosSessionAction;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ClosePosSessionRequest;
use App\Http\Requests\V1\OpenPosSessionRequest;
use App\Http\Resources\V1\PosSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PosSessionController extends Controller
{
    /**
     * List historical and active POS shifts with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PosSession::with(['cashier', 'warehouse'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->query('cashier_id'));
        }

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->query('terminal_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('opened_at', $request->query('date'));
        }

        $perPage = min((int) $request->query('per_page', 15), 50);

        return PosSessionResource::collection($query->paginate($perPage));
    }

    /**
     * Get the currently active open shift session for the authenticated cashier or terminal.
     */
    public function current(Request $request): JsonResponse
    {
        $cashierId = $request->user()?->id;
        $terminalId = $request->query('terminal_id');

        $query = PosSession::with(['cashier', 'warehouse'])->where('status', 'open');

        if ($terminalId) {
            $query->where('terminal_id', $terminalId);
        } elseif ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        $session = $query->first();

        if (! $session) {
            return response()->json([
                'success' => false,
                'hasActiveShift' => false,
                'message' => 'No active open shift found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'hasActiveShift' => true,
            'data' => new PosSessionResource($session),
        ]);
    }

    /**
     * Open a new cash register shift session.
     */
    public function open(OpenPosSessionRequest $request, OpenPosSessionAction $action): JsonResponse
    {
        $cashierId = $request->user()?->id ?? 1;
        $session = $action->execute($request->validated(), $cashierId);

        return response()->json([
            'success' => true,
            'message' => "Shift #{$session->session_no} opened successfully.",
            'data' => new PosSessionResource($session),
        ], 201);
    }

    /**
     * Show details of a specific POS shift.
     */
    public function show(int $id): JsonResponse
    {
        $session = PosSession::with(['cashier', 'warehouse'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PosSessionResource($session),
        ]);
    }

    /**
     * Close a POS shift session with blind cash count reconciliation.
     */
    public function close(ClosePosSessionRequest $request, int $id, ClosePosSessionAction $action): JsonResponse
    {
        $session = PosSession::findOrFail($id);
        $closedSession = $action->execute($session, $request->validated(), $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Shift #{$closedSession->session_no} closed successfully. Variance: " . number_format($closedSession->cash_variance, 2) . ' Ks.',
            'data' => new PosSessionResource($closedSession),
        ]);
    }

    /**
     * Generate an interim X-Report snapshot while shift is open.
     */
    public function xReport(int $id, GenerateShiftAuditReportAction $action): JsonResponse
    {
        $session = PosSession::findOrFail($id);
        $report = $action->execute($session);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * Generate the final closed Z-Report audit summary.
     */
    public function zReport(int $id, GenerateShiftAuditReportAction $action): JsonResponse
    {
        $session = PosSession::findOrFail($id);
        $report = $action->execute($session);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CreditSettlementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditSettlementController extends Controller
{
    /**
     * List global credit debt settlement receipts with multi-criteria filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CreditSettlement::with(['customer', 'cashier', 'posSession']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->query('cashier_id'));
        }

        if ($request->filled('pos_session_id')) {
            $query->where('pos_session_id', $request->query('pos_session_id'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('receipt_no', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $settlements = $query->latest('id')->paginate($perPage);

        return CreditSettlementResource::collection($settlements);
    }

    /**
     * Show single credit settlement receipt.
     */
    public function show(int $id): JsonResponse
    {
        $settlement = CreditSettlement::with(['customer', 'cashier', 'posSession'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new CreditSettlementResource($settlement),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Actions\VoidOrderAction;
use App\Domains\SalesAndPos\Models\HeldOrder;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CheckoutRequest;
use App\Http\Requests\V1\VoidOrderRequest;
use App\Http\Resources\V1\SalesOrderResource;
use App\Jobs\GenerateReceiptPdfJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PosController extends Controller
{
    /**
     * Process POS transactional checkout (ACID, Pessimistic stock locking, Multi-tender splits).
     */
    public function checkout(CheckoutRequest $request, ProcessCheckoutAction $action): JsonResponse
    {
        $payload = $request->validated();

        $cashierId = $payload['cashier_id'] ?? $request->user()?->id ?? 1;

        try {
            $order = $action->execute($payload, $cashierId);

            return response()->json([
                'success' => true,
                'message' => 'POS Checkout completed successfully.',
                'data' => new SalesOrderResource($order),
            ], 201);
        } catch (\DomainException|\App\Exceptions\CreditLimitExceededException|\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List historical sales orders with filters and branch/cashier scoping (OWASP API1:2023 BOLA Defense).
     */
    public function orders(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $isSupervisor = $user && ($user->hasRole('ADMIN') || $user->hasPermission('settings', 'manageRoles') || $user->hasPermission('pos', 'viewAllBranches'));

        $query = SalesOrder::with(['customer', 'vehicle', 'cashier', 'items.product', 'payments']);

        // Multi-Branch BOLA Scoping: Regular cashiers only see orders from their active shift session or their own sales
        if (! $isSupervisor) {
            $activeSession = PosSession::where('cashier_id', $user->id)
                ->where('status', 'open')
                ->latest('id')
                ->first();

            if ($activeSession) {
                $query->where('pos_session_id', $activeSession->id);
            } else {
                $query->where('cashier_id', $user->id);
            }
        } else {
            // Enterprise supervisors can filter across warehouses or cashiers
            if ($request->filled('warehouse_id')) {
                $query->whereHas('posSession', fn ($q) => $q->where('warehouse_id', $request->query('warehouse_id')));
            }

            if ($request->filled('cashier_id')) {
                $query->where('cashier_id', $request->query('cashier_id'));
            }

            if ($request->filled('pos_session_id')) {
                $query->where('pos_session_id', $request->query('pos_session_id'));
            }
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $orders = $query->latest('id')->paginate($perPage);

        return SalesOrderResource::collection($orders);
    }

    /**
     * Get single sales order details with BOLA branch-level authorization check.
     */
    public function showOrder(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isSupervisor = $user && ($user->hasRole('ADMIN') || $user->hasPermission('settings', 'manageRoles') || $user->hasPermission('pos', 'viewAllBranches'));

        $order = SalesOrder::with(['customer', 'vehicle', 'cashier', 'items.product', 'payments'])->findOrFail($id);

        if (! $isSupervisor) {
            $activeSession = PosSession::where('cashier_id', $user->id)
                ->where('status', 'open')
                ->latest('id')
                ->first();

            $isOwnOrder = ($activeSession && $order->pos_session_id === $activeSession->id) || $order->cashier_id === $user->id;

            if (! $isOwnOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have authorization to view sales orders outside your assigned branch counter session.',
                    'error_code' => 'BOLA_UNAUTHORIZED_BRANCH_ACCESS',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => new SalesOrderResource($order),
        ]);
    }

    /**
     * Trigger asynchronous thermal receipt reprint job.
     */
    public function reprintReceipt(int $id): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);

        GenerateReceiptPdfJob::dispatch($order->id);

        return response()->json([
            'success' => true,
            'message' => "Reprint job for order {$order->order_no} dispatched to receipts queue.",
        ]);
    }

    /**
     * Retrieve active parked cart sessions (Held Orders) scoped to cashier/shift.
     */
    public function listHeldOrders(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = HeldOrder::with(['customer', 'cashier']);

        // Cashiers only see held carts from their active session or created by themselves, unless supervisor/admin
        $isSupervisorOrAdmin = $user && ($user->role?->code === 'ADMIN' || (bool) ($user->role?->is_system ?? false) || $user->hasPermission('pos', 'voidInvoice'));

        if (! $isSupervisorOrAdmin && ! $request->boolean('all')) {
            $activeSessionId = PosSession::where('cashier_id', $user?->id)->where('status', 'open')->value('id');
            $query->where(function ($q) use ($user, $activeSessionId) {
                $q->where('cashier_id', $user?->id);
                if ($activeSessionId) {
                    $q->orWhere('pos_session_id', $activeSessionId);
                }
            });
        }

        $heldOrders = $query->latest('id')->get();

        return response()->json([
            'success' => true,
            'data' => $heldOrders,
        ]);
    }

    /**
     * Park cart temporarily on server linked to active shift session.
     */
    public function parkOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'cart_data' => ['required', 'array'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $cashierId = $request->user()?->id ?? 1;
        $activeSessionId = PosSession::where('cashier_id', $cashierId)->where('status', 'open')->value('id');

        $held = HeldOrder::create([
            'hold_id' => 'HOLD-' . strtoupper(uniqid()),
            'title' => $validated['title'],
            'customer_id' => $validated['customer_id'] ?? null,
            'cashier_id' => $cashierId,
            'pos_session_id' => $activeSessionId,
            'cart_data' => $validated['cart_data'],
            'subtotal' => $validated['subtotal'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cart session successfully parked.',
            'data' => $held,
        ], 201);
    }

    /**
     * Release or delete a parked cart session with ownership protection.
     */
    public function deleteHeldOrder(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $held = HeldOrder::findOrFail($id);

        $isSupervisorOrAdmin = $user && ($user->role?->code === 'ADMIN' || (bool) ($user->role?->is_system ?? false) || $user->hasPermission('pos', 'voidInvoice'));

        if (! $isSupervisorOrAdmin && $user && $held->cashier_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: You can only release or delete parked orders created by your terminal session.',
            ], 403);
        }

        $held->delete();

        return response()->json([
            'success' => true,
            'message' => 'Parked order released.',
        ]);
    }

    /**
     * Officially void an erroneous sales order under supervisor authorization.
     */
    public function voidOrder(int $id, VoidOrderRequest $request, VoidOrderAction $action): JsonResponse
    {
        try {
            $order = $action->execute(
                orderId: $id,
                reason: $request->validated('reason'),
                operatorId: $request->user()?->id,
                supervisorPin: $request->validated('supervisor_pin')
            );

            return response()->json([
                'success' => true,
                'message' => "Order #{$order->order_no} has been officially voided.",
                'data' => new SalesOrderResource($order),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

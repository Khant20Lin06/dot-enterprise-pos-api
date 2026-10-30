<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Models\HeldOrder;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CheckoutRequest;
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

        if (empty($payload['cashier_id']) && $request->user()) {
            $payload['cashier_id'] = $request->user()->id;
        }

        $order = $action->execute($payload);

        return response()->json([
            'success' => true,
            'message' => 'POS Checkout completed successfully.',
            'data' => new SalesOrderResource($order),
        ], 201);
    }

    /**
     * List historical sales orders with filters.
     */
    public function orders(Request $request): AnonymousResourceCollection
    {
        $query = SalesOrder::with(['customer', 'vehicle', 'cashier', 'items.product', 'payments']);

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
     * Get single sales order details.
     */
    public function showOrder(int $id): JsonResponse
    {
        $order = SalesOrder::with(['customer', 'vehicle', 'cashier', 'items.product', 'payments'])->findOrFail($id);

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
     * Retrieve active parked cart sessions (Held Orders).
     */
    public function listHeldOrders(): JsonResponse
    {
        $heldOrders = HeldOrder::with(['customer', 'cashier'])->latest('id')->get();

        return response()->json([
            'success' => true,
            'data' => $heldOrders,
        ]);
    }

    /**
     * Park cart temporarily on server.
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

        $held = HeldOrder::create([
            'hold_id' => 'HOLD-' . strtoupper(uniqid()),
            'title' => $validated['title'],
            'customer_id' => $validated['customer_id'] ?? null,
            'cashier_id' => $cashierId,
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
     * Release or delete a parked cart session.
     */
    public function deleteHeldOrder(int $id): JsonResponse
    {
        $held = HeldOrder::findOrFail($id);
        $held->delete();

        return response()->json([
            'success' => true,
            'message' => 'Parked order released.',
        ]);
    }
}

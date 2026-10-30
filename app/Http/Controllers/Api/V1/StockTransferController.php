<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Inventory\Actions\CancelStockTransferAction;
use App\Domains\Inventory\Actions\CreateStockTransferAction;
use App\Domains\Inventory\Actions\DispatchStockTransferAction;
use App\Domains\Inventory\Actions\ReceiveStockTransferAction;
use App\Domains\Inventory\Models\StockTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateStockTransferRequest;
use App\Http\Requests\V1\ReceiveStockTransferRequest;
use App\Http\Resources\V1\StockTransferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockTransferController extends Controller
{
    /**
     * List all stock transfers with warehouse and status filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockTransfer::with(['sourceWarehouse', 'destinationWarehouse', 'requester', 'approver', 'receiver', 'items.product']);

        if ($request->filled('source_warehouse_id')) {
            $query->where('source_warehouse_id', $request->query('source_warehouse_id'));
        }

        if ($request->filled('destination_warehouse_id')) {
            $query->where('destination_warehouse_id', $request->query('destination_warehouse_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $transfers = $query->latest('id')->paginate($perPage);

        return StockTransferResource::collection($transfers);
    }

    /**
     * Create a new draft stock transfer order.
     */
    public function store(CreateStockTransferRequest $request, CreateStockTransferAction $action): JsonResponse
    {
        $transfer = $action->execute($request->validated(), $request->user()?->id ?? 1);

        return response()->json([
            'success' => true,
            'message' => "Stock Transfer #{$transfer->transfer_no} drafted successfully.",
            'data' => new StockTransferResource($transfer),
        ], 201);
    }

    /**
     * View details of a specific stock transfer order.
     */
    public function show(int $id): JsonResponse
    {
        $transfer = StockTransfer::with(['sourceWarehouse', 'destinationWarehouse', 'requester', 'approver', 'receiver', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new StockTransferResource($transfer),
        ]);
    }

    /**
     * Dispatch stock transfer order, deducting source warehouse stock.
     */
    public function dispatchTransfer(int $id, DispatchStockTransferAction $action, Request $request): JsonResponse
    {
        $transfer = StockTransfer::findOrFail($id);
        $dispatched = $action->execute($transfer, $request->user()?->id ?? 1);

        return response()->json([
            'success' => true,
            'message' => "Transfer #{$dispatched->transfer_no} dispatched. Goods are now in transit.",
            'data' => new StockTransferResource($dispatched),
        ]);
    }

    /**
     * Receive in-transit stock transfer at destination warehouse.
     */
    public function receiveTransfer(ReceiveStockTransferRequest $request, int $id, ReceiveStockTransferAction $action): JsonResponse
    {
        $transfer = StockTransfer::findOrFail($id);

        $quantitiesMap = null;
        if ($request->filled('received_items')) {
            $quantitiesMap = [];
            foreach ($request->input('received_items') as $row) {
                $quantitiesMap[$row['transfer_item_id']] = (int) $row['qty_received'];
            }
        }

        $received = $action->execute($transfer, $request->user()?->id ?? 1, $quantitiesMap);

        return response()->json([
            'success' => true,
            'message' => "Transfer #{$received->transfer_no} received successfully. Destination inventory updated.",
            'data' => new StockTransferResource($received),
        ]);
    }

    /**
     * Cancel draft or in-transit transfer.
     */
    public function cancelTransfer(int $id, CancelStockTransferAction $action, Request $request): JsonResponse
    {
        $transfer = StockTransfer::findOrFail($id);
        $cancelled = $action->execute($transfer, $request->user()?->id ?? 1);

        return response()->json([
            'success' => true,
            'message' => "Transfer #{$cancelled->transfer_no} cancelled successfully.",
            'data' => new StockTransferResource($cancelled),
        ]);
    }
}

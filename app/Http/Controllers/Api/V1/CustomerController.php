<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\CreateCustomerRequest;
use App\Http\Requests\V1\UpdateCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    /**
     * List customers with search, debt filters, tier, and fleet relations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Customer::with(['fleet', 'vehicles']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('has_debt')) {
            $query->where('due_amount', '>', 0);
        }

        if ($request->filled('tier')) {
            $query->where('tier', $request->query('tier'));
        }

        if ($request->filled('fleet_id')) {
            $query->where('fleet_id', $request->query('fleet_id'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $customers = $query->orderByDesc('due_amount')->latest('id')->paginate($perPage);

        return CustomerResource::collection($customers);
    }

    /**
     * Register a new customer.
     */
    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Customer registered successfully.',
            'data' => new CustomerResource($customer->load(['fleet', 'vehicles'])),
        ], 201);
    }

    /**
     * Show single customer details.
     */
    public function show(int $id): JsonResponse
    {
        $customer = Customer::with(['fleet', 'vehicles', 'priceList'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Update customer profile and credit settings.
     */
    public function update(UpdateCustomerRequest $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data' => new CustomerResource($customer->fresh(['fleet', 'vehicles'])),
        ]);
    }

    /**
     * Delete customer (guarded against active debt and transaction history).
     */
    public function destroy(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if ((float) $customer->due_amount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete customer #{$id}. Outstanding receivable debt is {$customer->due_amount} Ks.",
            ], 422);
        }

        if ($customer->salesOrders()->exists() || $customer->workOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete customer #{$id} with historical sales orders or workshop job cards. Retain for financial audit integrity.",
            ], 422);
        }

        if ($customer->creditSettlements()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete customer #{$id}. Historical credit debt settlement receipts exist. Retain for financial ledger integrity.",
            ], 422);
        }

        if ($customer->salesReturns()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete customer #{$id}. Historical sales return and refund records exist.",
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }
}

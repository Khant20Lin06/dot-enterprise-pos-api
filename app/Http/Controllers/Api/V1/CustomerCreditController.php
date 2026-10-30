<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction;
use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\SettleCreditRequest;
use App\Http\Resources\V1\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerCreditController extends Controller
{
    /**
     * List customers with search, debt filters, and fleet relations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Customer::with(['fleet', 'vehicles']);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('has_debt')) {
            $query->where('current_due', '>', 0);
        }

        if ($request->filled('fleet_id')) {
            $query->where('fleet_id', $request->query('fleet_id'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $customers = $query->orderByDesc('current_due')->paginate($perPage);

        return CustomerResource::collection($customers);
    }

    /**
     * Get single customer details with vehicles and credit standing.
     */
    public function show(int $id): JsonResponse
    {
        $customer = Customer::with(['fleet', 'vehicles'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Settle customer debt balance with sequential receipt ledger.
     */
    public function settleCredit(int $id, SettleCreditRequest $request, SettleCustomerCreditAction $action): JsonResponse
    {
        $cashierId = $request->cashier_id ?? ($request->user()?->id ?? 1);

        $settlement = $action->execute(
            customerId: $id,
            amount: (float) $request->amount,
            paymentMethod: $request->payment_method,
            cashierId: $cashierId,
            note: $request->note
        );

        $customer = Customer::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Customer credit debt settled successfully.',
            'data' => [
                'settlement' => $settlement,
                'customer' => new CustomerResource($customer),
            ],
        ]);
    }

    /**
     * Retrieve complete ledger history (invoices with due balances and settlement receipts).
     */
    public function ledger(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $unpaidInvoices = SalesOrder::where('customer_id', $id)
            ->where('due_amount', '>', 0)
            ->latest('id')
            ->get(['id', 'order_no', 'total_amount', 'paid_amount', 'due_amount', 'created_at']);

        $settlements = CreditSettlement::where('customer_id', $id)
            ->with('cashier')
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'creditLimit' => (float) $customer->credit_limit,
                'currentDue' => (float) $customer->current_due,
            ],
            'unpaidInvoices' => $unpaidInvoices,
            'settlements' => $settlements,
        ]);
    }
}

<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Actions\ReceivePurchaseAction;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use App\Exceptions\InsufficientStockException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($this->adminStaff);

    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);

    $this->product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Engine Oil Filter Toyota',
        'barcode' => 'OF-TYT-' . uniqid(),
        'sell_price' => 20000.00,
        'cost_price' => 12000.00,
        'stock' => 50,
        'is_labor' => false,
    ]);

    $this->warehouse = Warehouse::create([
        'code' => 'WH-CONC-' . uniqid(),
        'name' => 'Concurrency Audit Hub',
        'is_active' => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| Test 1: Loyalty Points Redemption Monetary Valuation (BIZ-01)
|--------------------------------------------------------------------------
*/

it('converts redeemed loyalty points into monetary price discount and updates balances', function () {
    $customer = Customer::create([
        'name' => 'Ko Aung Points Holder',
        'phone' => '0999887766',
        'loyalty_points' => 100, // 100 points = 10,000 Ks at 100 Ks/pt
        'due_amount' => 0.00,
    ]);

    $checkoutAction = app(ProcessCheckoutAction::class);

    // Buy 1 item (20,000 Ks), redeem 30 points (3,000 Ks discount)
    // Net payable total = 20,000 - 3,000 = 17,000 Ks
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'points_redeemed' => 30,
        'items' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 17000.00],
        ],
    ], $this->adminStaff->id);

    expect((float) $order->total_amount)->toBe(17000.00)
        ->and((float) $order->discount_amount)->toBe(3000.00)
        ->and((int) $order->points_redeemed)->toBe(30);

    // Customer points: 100 - 30 redeemed + 17 earned on 17,000 Ks = 87 points
    expect((int) $customer->fresh()->loyalty_points)->toBe(87);
});

/*
|--------------------------------------------------------------------------
| Test 2: Strict Warehouse Stock Row Lock & Anti-Drift (BIZ-02)
|--------------------------------------------------------------------------
*/

it('strictly enforces warehouse inventory row presence and rejects deduction when unallocated', function () {
    $deductAction = app(DeductStockAction::class);

    // 1. Product exists in catalog, but NO WarehouseStock record exists for $this->warehouse
    expect(fn () => $deductAction->execute($this->product->id, 2, null, $this->warehouse->id))
        ->toThrow(InsufficientStockException::class);

    // 2. Allocate 5 units in warehouse
    $whStock = WarehouseStock::create([
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'stock_qty' => 5,
    ]);

    // Attempting to deduct 10 units must fail
    expect(fn () => $deductAction->execute($this->product->id, 10, null, $this->warehouse->id))
        ->toThrow(InsufficientStockException::class);

    // Deduct 3 units: succeeds and decrements both
    $deductAction->execute($this->product->id, 3, null, $this->warehouse->id);

    expect((int) $whStock->fresh()->stock_qty)->toBe(2)
        ->and((int) $this->product->fresh()->stock)->toBe(47); // 50 - 3
});

/*
|--------------------------------------------------------------------------
| Test 3: Workshop Labor Service Line Item Invariant (BIZ-04)
|--------------------------------------------------------------------------
*/

it('guarantees workshop labor line item presence and total ledger balancing', function () {
    // Delete any pre-existing labor products to test auto-fallback
    Product::where('is_labor', true)->delete();

    $customer = Customer::create([
        'name' => 'Workshop Hilux Owner',
        'phone' => '0944332211',
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => 'YGN-WS-' . uniqid(),
        'customer_id' => $customer->id,
        'make' => 'Toyota',
        'model' => 'Hilux Revo',
    ]);

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-LABOR-LEDGER',
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'QC & Ready',
        'complaint' => 'Brake pad replacement and disc skimming',
        'parts_total' => 20000.00,
        'labor_fee' => 30000.00,
        'total_amount' => 50000.00,
    ]);

    $workOrder->items()->create([
        'product_id' => $this->product->id,
        'name' => $this->product->name,
        'qty' => 1,
        'unit_price' => 20000.00,
        'subtotal' => 20000.00,
    ]);

    $convertAction = app(ConvertWorkOrderToInvoiceAction::class);
    $result = $convertAction->execute(
        workOrderId: $workOrder->id,
        paymentMethod: 'Cash',
        cashierId: $this->adminStaff->id
    );

    $salesOrder = $result['sales_order'];
    expect((float) $salesOrder->total_amount)->toBe(50000.00);

    // Sum of items must strictly equal header total amount
    $sumOfItems = (float) $salesOrder->items->sum('subtotal');
    expect($sumOfItems)->toBe(50000.00);

    // Verify labor line item exists
    $laborLine = $salesOrder->items->firstWhere('unit_price', 30000.00);
    expect($laborLine)->not->toBeNull()
        ->and((float) $laborLine->subtotal)->toBe(30000.00);
});

/*
|--------------------------------------------------------------------------
| Test 4: Supplier Pessimistic Locking on Purchase Inflow (CONC-01)
|--------------------------------------------------------------------------
*/

it('atomically increments supplier payable balance with row locking on goods receipt', function () {
    $supplier = Supplier::create([
        'name' => 'Denso Parts Importer',
        'phone' => '0955667788',
        'outstanding_balance' => 100000.00,
    ]);

    $purchase = Purchase::create([
        'po_no' => 'PO-CONC-' . uniqid(),
        'supplier_id' => $supplier->id,
        'status' => 'Pending',
        'total_amount' => 150000.00,
    ]);

    PurchaseItem::create([
        'purchase_id' => $purchase->id,
        'product_id' => $this->product->id,
        'qty' => 10,
        'unit_price' => 15000.00,
        'subtotal' => 150000.00,
    ]);

    $receiveAction = app(ReceivePurchaseAction::class);
    $receiveAction->execute(
        purchaseId: $purchase->id,
        warehouseId: null,
        operatorId: $this->adminStaff->id
    );

    expect($purchase->fresh()->status)->toBe('Received');
    // Supplier balance updated from 100,000 to 250,000 Ks
    expect((float) $supplier->fresh()->outstanding_balance)->toBe(250000.00);
});

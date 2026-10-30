<?php

use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Models\SalesReturn;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Domains\Workshop\Models\OutsourcedPart;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($this->adminStaff);

    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);
});

/*
|--------------------------------------------------------------------------
| Test 1: Mechanic Commission Cascade Delete Prevention Guard
|--------------------------------------------------------------------------
*/

it('blocks deleting a mechanic when historical commission records exist', function () {
    $mechanic = Mechanic::create([
        'name' => 'Ko Zaw Mechanic ' . uniqid(),
        'phone' => '0911223344',
        'specialization' => 'Suspension',
        'status' => 'Active',
    ]);

    $customer = Customer::create(['name' => 'Customer Zaw', 'phone' => '0988776655']);
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id,
        'plate_number' => 'YGN-' . uniqid(),
        'make' => 'Toyota',
        'model' => 'Alphard',
    ]);

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'Completed / Invoiced',
        'complaint' => 'Brake service',
    ]);

    // Create historical earned commission
    $commission = MechanicCommission::create([
        'mechanic_id' => $mechanic->id,
        'work_order_id' => $workOrder->id,
        'labor_amount' => 50000.00,
        'commission_rate' => 20.00,
        'earned_amount' => 10000.00,
        'status' => 'Paid',
        'paid_at' => now(),
    ]);

    $response = $this->deleteJson("/api/v1/workshop/mechanics/{$mechanic->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Mechanic::find($mechanic->id))->not->toBeNull()
        ->and(MechanicCommission::find($commission->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Test 2: Product Relational Delete Guards (Purchase Items & Physical Stock)
|--------------------------------------------------------------------------
*/

it('blocks deleting a product referenced in purchase orders or with physical stock', function () {
    $supplier = Supplier::create([
        'name' => 'Test Supplier ' . uniqid(),
        'phone' => '0911223344',
    ]);

    // 1. Product linked to purchase items (stock 0)
    $productWithPo = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Brake Caliper PO ' . uniqid(),
        'barcode' => 'BC-' . uniqid(),
        'sell_price' => 45000.00,
        'cost_price' => 30000.00,
        'stock' => 0,
        'is_labor' => false,
    ]);

    $po = Purchase::create([
        'po_no' => 'PO-' . uniqid(),
        'supplier_id' => $supplier->id,
        'total_amount' => 30000.00,
        'status' => 'Received',
    ]);

    PurchaseItem::create([
        'purchase_id' => $po->id,
        'product_id' => $productWithPo->id,
        'qty' => 1,
        'unit_price' => 30000.00,
        'subtotal' => 30000.00,
    ]);

    $deletePoResponse = $this->deleteJson("/api/v1/products/{$productWithPo->id}");
    $deletePoResponse->assertStatus(422)
        ->assertJsonPath('success', false);

    // 2. Product with physical stock > 0
    $productWithStock = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Oil Filter Stock ' . uniqid(),
        'barcode' => 'OF-' . uniqid(),
        'sell_price' => 15000.00,
        'cost_price' => 9000.00,
        'stock' => 25,
        'is_labor' => false,
    ]);

    $deleteStockResponse = $this->deleteJson("/api/v1/products/{$productWithStock->id}");
    $deleteStockResponse->assertStatus(422)
        ->assertJsonPath('success', false);
});

/*
|--------------------------------------------------------------------------
| Test 3: Supplier Relational Delete Guards (Outsourced Parts & AP Balance)
|--------------------------------------------------------------------------
*/

it('blocks deleting a supplier with outstanding balance or outsourced machining parts', function () {
    // 1. Supplier with outstanding AP debt
    $debtSupplier = Supplier::create([
        'name' => 'Debt Machining Works ' . uniqid(),
        'phone' => '0922334455',
        'outstanding_balance' => 750000.00,
    ]);

    $debtRes = $this->deleteJson("/api/v1/suppliers/{$debtSupplier->id}");
    $debtRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // 2. Supplier with outsourced parts history
    $cleanSupplier = Supplier::create([
        'name' => 'Lathe Shop ' . uniqid(),
        'phone' => '0933445566',
        'outstanding_balance' => 0.00,
    ]);

    $customer = Customer::create(['name' => 'Customer Lathe', 'phone' => '0977665544']);
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id,
        'plate_number' => 'MDY-' . uniqid(),
        'make' => 'Toyota',
        'model' => 'Hilux',
    ]);
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-OUT-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'In Progress',
        'complaint' => 'Flywheel resurfacing',
    ]);

    OutsourcedPart::create([
        'work_order_id' => $workOrder->id,
        'supplier_id' => $cleanSupplier->id,
        'part_name' => 'Flywheel Lathe Turning',
        'cost_price' => 35000.00,
        'bill_price' => 50000.00,
        'status' => 'Ordered',
    ]);

    $outRes = $this->deleteJson("/api/v1/suppliers/{$cleanSupplier->id}");
    $outRes->assertStatus(422)
        ->assertJsonPath('success', false);
});

/*
|--------------------------------------------------------------------------
| Test 4: Customer Relational Delete Guards (Credit Debt Settlements)
|--------------------------------------------------------------------------
*/

it('blocks deleting a customer with historical credit settlement receipts', function () {
    $customer = Customer::create([
        'name' => 'U Ba Than Settled',
        'phone' => '0933445566',
        'credit_limit' => 200000.00,
        'due_amount' => 0.00,
    ]);

    CreditSettlement::create([
        'receipt_no' => 'REC-SAFE-' . uniqid(),
        'customer_id' => $customer->id,
        'amount' => 50000.00,
        'tendered_amount' => 50000.00,
        'change_amount' => 0.00,
        'payment_method' => 'Cash',
        'cashier_id' => $this->adminStaff->id,
    ]);

    $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(Customer::find($customer->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Test 5: Warehouse-Level Negative Stock Invariant in Stock Adjustments
|--------------------------------------------------------------------------
*/

it('rejects stock adjustment when target warehouse stock would become negative', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Transmission Fluid Multi-WH',
        'barcode' => 'TF-' . uniqid(),
        'sell_price' => 35000.00,
        'cost_price' => 22000.00,
        'stock' => 50,
        'is_labor' => false,
    ]);

    $warehouse = Warehouse::create([
        'code' => 'WH-YGN-' . uniqid(),
        'name' => 'Yangon Downtown Hub',
        'is_active' => true,
    ]);

    // Target warehouse only has 10 units
    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_option_id' => null,
        'stock_qty' => 10,
    ]);

    // Attempt to adjust -25 units from this warehouse
    $response = $this->postJson('/api/v1/stock-adjustments', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'type' => 'Damage / Scrap',
        'adjusted_qty' => -25,
        'reason' => 'Leakage damage during transit',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    // Verify warehouse stock and product stock remain untouched
    $product->refresh();
    $whStock = WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
    expect($product->stock)->toBe(50)
        ->and($whStock->stock_qty)->toBe(10);
});

/*
|--------------------------------------------------------------------------
| Test 6: Outsourced Part Deletion Safeguards (Invoiced & Fitted Parts)
|--------------------------------------------------------------------------
*/

it('blocks deleting outsourced parts for completed work orders or fitted parts', function () {
    $supplier = Supplier::create([
        'name' => 'Precision Engineering',
        'phone' => '0944556677',
    ]);
    $customer = Customer::create(['name' => 'Ko Aung Out', 'phone' => '0944556677']);
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id,
        'plate_number' => 'NPT-' . uniqid(),
        'make' => 'Nissan',
        'model' => 'Caravan',
    ]);

    // 1. Part on invoiced work order
    $invoicedWorkOrder = WorkOrder::create([
        'job_card_no' => 'JC-INV-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'Completed / Invoiced',
        'complaint' => 'Engine overhaul',
    ]);

    $invoicedPart = OutsourcedPart::create([
        'work_order_id' => $invoicedWorkOrder->id,
        'supplier_id' => $supplier->id,
        'part_name' => 'Crankshaft Grinding',
        'cost_price' => 80000.00,
        'bill_price' => 120000.00,
        'status' => 'Received & Fitted',
    ]);

    $invRes = $this->deleteJson("/api/v1/workshop/outsourced-parts/{$invoicedPart->id}");
    $invRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // 2. Part marked Received & Fitted on an open work order
    $openWorkOrder = WorkOrder::create([
        'job_card_no' => 'JC-OPEN-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'In Progress',
        'complaint' => 'Brake lathe',
    ]);

    $fittedPart = OutsourcedPart::create([
        'work_order_id' => $openWorkOrder->id,
        'supplier_id' => $supplier->id,
        'part_name' => 'Brake Disc Machining',
        'cost_price' => 20000.00,
        'bill_price' => 30000.00,
        'status' => 'Received & Fitted',
    ]);

    $fitRes = $this->deleteJson("/api/v1/workshop/outsourced-parts/{$fittedPart->id}");
    $fitRes->assertStatus(422)
        ->assertJsonPath('success', false);
});

/*
|--------------------------------------------------------------------------
| Test 7: Sales Return Idempotency & Duplicate Refund Protection
|--------------------------------------------------------------------------
*/

it('enforces idempotency on sales returns to prevent duplicate cash refunds and double restock', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Spark Plug Iridium',
        'barcode' => 'SP-IR-' . uniqid(),
        'sell_price' => 20000.00,
        'cost_price' => 12000.00,
        'stock' => 50,
        'is_labor' => false,
    ]);

    $customer = Customer::create(['name' => 'Mg Mg Idem', 'phone' => '0955667788']);

    // Complete POS Checkout
    $checkoutAction = app(ProcessCheckoutAction::class);
    $salesOrder = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 20000.00,
            ],
        ],
        'payments' => [
            [
                'method' => 'Cash',
                'amount' => 40000.00,
            ],
        ],
    ], $this->adminStaff->id);

    $product->refresh();
    expect($product->stock)->toBe(48);

    $idempotencyKey = 'IDEM-REFUND-' . uniqid();
    $refundPayload = [
        'sales_order_id' => $salesOrder->id,
        'reason' => 'Defective packaging',
        'refund_method' => 'Cash',
        'idempotency_key' => $idempotencyKey,
        'items' => [
            [
                'sales_order_item_id' => $salesOrder->items->first()->id,
                'qty' => 1,
                'item_condition' => 'Sellable',
            ],
        ],
    ];

    // 1. First refund request: processes refund and restocks 1 unit
    $res1 = $this->postJson('/api/v1/sales-returns', $refundPayload);
    $res1->assertCreated()
        ->assertJsonPath('success', true);

    $returnNo = $res1->json('data.returnNo');

    $product->refresh();
    expect($product->stock)->toBe(49);

    // 2. Duplicate second refund request with identical idempotency_key
    $res2 = $this->postJson('/api/v1/sales-returns', $refundPayload);
    $res2->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.returnNo', $returnNo);

    // CRITICAL ASSERTION: Stock MUST NOT be restocked twice, and only 1 SalesReturn record must exist
    $product->refresh();
    expect($product->stock)->toBe(49);
    expect(SalesReturn::where('sales_order_id', $salesOrder->id)->count())->toBe(1);
});

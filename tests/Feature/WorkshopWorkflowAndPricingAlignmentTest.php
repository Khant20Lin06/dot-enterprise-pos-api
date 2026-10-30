<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\PricingAndPromotions\Models\PriceListItem;
use App\Domains\SalesAndPos\Events\OrderCompletedEvent;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Events\WorkOrderStatusChangedEvent;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->first()
        ?? StaffUser::first();
});

it('updates open workshop job card details, modifies line items, and recalculates totals', function () {
    Sanctum::actingAs($this->adminStaff);

    $customer = Customer::first();
    $vehicle = Vehicle::where('customer_id', $customer->id)->first() ?? Vehicle::create([
        'plate_number' => 'TEST-PLT-001',
        'customer_id' => $customer->id,
        'make' => 'Toyota',
        'model' => 'Crown',
    ]);
    $part = Product::where('is_labor', false)->first();

    // 1. Create Job Card
    $createResponse = $this->postJson('/api/v1/workshop/work-orders', [
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'complaint' => 'Engine rattling sound at high speed',
        'mileage_in' => 50000,
        'items' => [
            [
                'product_id' => $part->id,
                'name' => $part->name,
                'qty' => 1,
                'unit_price' => 20000.00,
                'item_type' => 'part',
            ],
        ],
    ]);

    $createResponse->assertStatus(201);
    $workOrderId = $createResponse->json('data.id');

    // 2. Update Job Card: add labor fee and modify complaint
    $updateResponse = $this->putJson("/api/v1/workshop/work-orders/{$workOrderId}", [
        'complaint' => 'Engine rattling resolved to loose exhaust bracket',
        'mileage_in' => 50050,
        'items' => [
            [
                'product_id' => $part->id,
                'name' => $part->name,
                'qty' => 2,
                'unit_price' => 20000.00,
                'item_type' => 'part',
            ],
            [
                'product_id' => $part->id,
                'name' => 'Bracket Welding Labor',
                'qty' => 1,
                'unit_price' => 15000.00,
                'item_type' => 'labor',
            ],
        ],
    ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'complaint' => 'Engine rattling resolved to loose exhaust bracket',
                'partsTotal' => 40000.00,
                'laborTotal' => 15000.00,
                'grandTotal' => 55000.00,
            ],
        ]);

    $workOrder = WorkOrder::findOrFail($workOrderId);
    expect((float) $workOrder->total_amount)->toEqual(55000.00);
});

it('manages workshop state transitions, broadcasts reverb event, and allows cancellation', function () {
    Event::fake([WorkOrderStatusChangedEvent::class]);
    Sanctum::actingAs($this->adminStaff);

    $customer = Customer::first();
    $vehicle = Vehicle::where('customer_id', $customer->id)->first() ?? Vehicle::create([
        'plate_number' => 'TEST-PLT-002',
        'customer_id' => $customer->id,
        'make' => 'Toyota',
        'model' => 'Camry',
    ]);

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-STATE-TEST',
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'Intake / Estimate',
        'complaint' => 'AC not cooling',
        'total_amount' => 30000.00,
    ]);

    // 1. Transition to 'In Progress'
    $statusResponse = $this->patchJson("/api/v1/workshop/work-orders/{$workOrder->id}/status", [
        'status' => 'In Progress',
    ]);

    $statusResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => ['status' => 'In Progress'],
        ]);

    Event::assertDispatched(WorkOrderStatusChangedEvent::class, function ($event) use ($workOrder) {
        return $event->workOrder->id === $workOrder->id && $event->previousStatus === 'Intake / Estimate';
    });

    // 2. Cancel Job Card
    $cancelResponse = $this->postJson("/api/v1/workshop/work-orders/{$workOrder->id}/cancel");
    $cancelResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    $workOrder->refresh();
    expect($workOrder->status)->toBe('Cancelled');

    // 3. Guard: Cannot update cancelled job card
    $blockUpdate = $this->putJson("/api/v1/workshop/work-orders/{$workOrder->id}", [
        'complaint' => 'Trying to edit cancelled card',
    ]);
    $blockUpdate->assertStatus(422);
});

it('converts workshop job card to invoice with multi-tender split payments and shift association', function () {
    Sanctum::actingAs($this->adminStaff);

    $customer = Customer::first();
    $vehicle = Vehicle::where('customer_id', $customer->id)->first() ?? Vehicle::create([
        'plate_number' => 'TEST-PLT-003',
        'customer_id' => $customer->id,
        'make' => 'Toyota',
        'model' => 'Hilux',
    ]);

    $part = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-SPLIT-TEST',
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'QC & Ready',
        'complaint' => 'Major 40,000 km Service',
        'parts_total' => 60000.00,
        'labor_fee' => 40000.00,
        'total_amount' => 100000.00,
    ]);

    $workOrder->items()->create([
        'product_id' => $part->id,
        'name' => $part->name,
        'qty' => 1,
        'unit_price' => 60000.00,
        'subtotal' => 60000.00,
    ]);

    // Open cashier shift session
    $session = PosSession::create([
        'session_no' => 'SHIFT-WS-001',
        'cashier_id' => $this->adminStaff->id,
        'terminal_id' => 'TERM-01',
        'opening_float' => 100000.00,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    // Convert to invoice with split tender: 40,000 Cash + 60,000 KPay
    $convertResponse = $this->postJson("/api/v1/workshop/work-orders/{$workOrder->id}/convert-to-invoice", [
        'payments' => [
            ['method' => 'Cash', 'amount' => 40000.00],
            ['method' => 'KPay', 'amount' => 60000.00, 'reference_no' => 'KP-WS-7788'],
        ],
    ]);

    $convertResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Job Card successfully converted to official POS invoice.',
            'data' => [
                'salesOrder' => [
                    'totalAmount' => 100000.00,
                    'paidAmount' => 100000.00,
                ],
            ],
        ]);

    $salesOrderId = $convertResponse->json('data.salesOrder.id');
    $salesOrder = SalesOrder::with('payments')->findOrFail($salesOrderId);

    expect($salesOrder->payments)->toHaveCount(2)
        ->and($salesOrder->pos_session_id)->toBe($session->id);
});

it('automatically resolves dynamic wholesale tier pricing during POS checkout for assigned customer', function () {
    Sanctum::actingAs($this->adminStaff);

    // Create Wholesale Price List
    $priceList = PriceList::create([
        'code' => 'PL-WHOLESALE-A',
        'name' => 'Wholesale Tier A',
        'type' => 'WHOLESALE',
        'is_active' => true,
    ]);

    $customer = Customer::create([
        'name' => 'Aung Auto Spares Wholesaler',
        'phone' => '09790011223',
        'tier' => 'VIP',
        'price_list_id' => $priceList->id,
    ]);

    $product = Product::create([
        'category_id' => Category::first()->id,
        'uom_id' => UnitOfMeasure::first()->id,
        'name' => 'Dynamic Price Brake Pad',
        'barcode' => 'DP-BRK-001',
        'cost_price' => 20000.00,
        'sell_price' => 35000.00,
        'wholesale_price' => 28000.00,
        'stock' => 50,
    ]);

    // Checkout 5 units without specifying unit_price in payload
    $checkoutResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 5,
            ],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 140000.00], // 5 * 28,000 = 140,000 Ks
        ],
    ]);

    $checkoutResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'totalAmount' => 140000.00,
            ],
        ]);

    $product->refresh();
    expect($product->stock)->toBe(45);
});

it('processes sales return for packaging UOM items and restocks proportional base inventory', function () {
    Sanctum::actingAs($this->adminStaff);

    $product = Product::create([
        'category_id' => Category::first()->id,
        'uom_id' => UnitOfMeasure::first()->id,
        'name' => 'Packaging UOM Returnable Oil',
        'barcode' => 'UOM-RET-001',
        'cost_price' => 30000.00,
        'sell_price' => 45000.00,
        'stock' => 100,
    ]);

    $boxUom = UnitOfMeasure::where('name', '!=', $product->uom?->name)->first()
        ?? UnitOfMeasure::create(['name' => 'Carton Box']);

    $conversion = ProductUomConversion::create([
        'product_id' => $product->id,
        'uom_id' => $boxUom->id,
        'unit_name' => 'Carton Box',
        'conversion_factor' => 10.0, // 1 Box = 10 Cans
        'barcode' => 'BOX-RET-001',
        'sell_price' => 400000.00, // 40,000 Ks per can in box
    ]);

    // 1. Checkout 1 Box (deducts 10 cans)
    $checkoutResponse = $this->postJson('/api/v1/pos/checkout', [
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'uom_conversion_id' => $conversion->id,
            ],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 400000.00],
        ],
    ]);

    $checkoutResponse->assertStatus(201);
    $salesOrderId = $checkoutResponse->json('data.id');
    $orderItem = SalesOrder::find($salesOrderId)->items->first();

    $product->refresh();
    expect($product->stock)->toBe(90);

    // 2. Return the 1 Box
    $returnResponse = $this->postJson('/api/v1/sales-returns', [
        'sales_order_id' => $salesOrderId,
        'refund_method' => 'Cash',
        'reason' => 'Customer ordered incorrect viscosity box',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'packaging_qty' => 1,
                'is_packaging' => true,
                'item_condition' => 'Sellable',
            ],
        ],
    ]);

    $returnResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'totalRefundAmount' => 400000.00,
            ],
        ]);

    // 3. Verify 10 base units were restocked
    $product->refresh();
    expect($product->stock)->toBe(100);
});

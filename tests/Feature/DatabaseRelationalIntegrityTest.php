<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\WorkOrder;
use App\Domains\Workshop\Models\WorkOrderItem;
use App\Domains\Workshop\Models\OutsourcedPart;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesOrderItem;
use App\Domains\SalesAndPos\Models\PaymentSplit;

uses(DatabaseTransactions::class);

it('has seeded master roles and staff with proper permissions', function () {
    $admin = StaffUser::where('email', 'admin@dotpos.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->role->code)->toBe('ADMIN');
    expect($admin->hasPermission('pos', 'create'))->toBeTrue();
    expect($admin->hasPermission('inventory', 'adjustStock'))->toBeTrue();
});

it('verifies strict relational linkage between customer, fleet, and vehicle', function () {
    $vehicle = Vehicle::where('plate_number', '3I/4411')->first();
    expect($vehicle)->not->toBeNull();
    expect($vehicle->customer)->toBeInstanceOf(Customer::class);
    expect($vehicle->customer->name)->toContain('U Hla Maung');
    expect($vehicle->fleet)->toBeInstanceOf(Fleet::class);
    expect($vehicle->fleet->name)->toContain('KBZ');
});

it('verifies work order items are strictly linked to catalog products', function () {
    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $product = Product::where('is_labor', false)->first();

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-TEST-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'complaint' => 'Engine knocking noise',
        'labor_fee' => 35000.00,
        'parts_total' => $product->sell_price,
        'total_amount' => 35000.00 + $product->sell_price,
    ]);

    $item = WorkOrderItem::create([
        'work_order_id' => $workOrder->id,
        'product_id' => $product->id, // Relational Foreign Key
        'name' => $product->name,
        'qty' => 1,
        'unit_price' => $product->sell_price,
        'cost_price' => $product->cost_price,
        'subtotal' => $product->sell_price,
    ]);

    expect($workOrder->items)->toHaveCount(1);
    expect($workOrder->items->first()->product->id)->toBe($product->id);
    expect($workOrder->items->first()->product->stock)->toBeGreaterThan(0);
});

it('verifies outsourced parts are strictly linked to registered suppliers', function () {
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-TEST-' . uniqid(),
        'customer_id' => Customer::first()->id,
        'vehicle_id' => Vehicle::first()->id,
        'complaint' => 'Brake sensor fault',
        'labor_fee' => 10000.00,
        'parts_total' => 25000.00,
        'total_amount' => 35000.00,
    ]);

    $supplier = Supplier::first();

    $part = OutsourcedPart::create([
        'work_order_id' => $workOrder->id,
        'supplier_id' => $supplier->id,
        'part_name' => 'ABS Speed Sensor (Rear Right)',
        'cost_price' => 18000.00,
        'bill_price' => 25000.00,
        'status' => 'Ordered',
    ]);

    expect($part->supplier)->toBeInstanceOf(Supplier::class);
    expect($part->supplier->name)->toBe($supplier->name);
    expect($part->profit())->toEqual(7000.00);
});

it('verifies sales order creates multi-tender payment splits and links relationally', function () {
    $customer = Customer::first();
    $cashier = StaffUser::first();
    $product = Product::first();

    $order = SalesOrder::create([
        'order_no' => 'INV-TEST-' . uniqid(),
        'customer_id' => $customer->id,
        'cashier_id' => $cashier->id,
        'subtotal' => 100000.00,
        'total_amount' => 100000.00,
        'paid_amount' => 100000.00,
        'due_amount' => 0.00,
        'status' => 'Completed',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'qty' => 1,
        'unit_price' => 100000.00,
        'cost_price' => 70000.00,
        'subtotal' => 100000.00,
    ]);

    PaymentSplit::create([
        'sales_order_id' => $order->id,
        'method' => 'Cash',
        'amount' => 40000.00,
    ]);

    PaymentSplit::create([
        'sales_order_id' => $order->id,
        'method' => 'KPay',
        'amount' => 60000.00,
        'reference_no' => 'KP-123456789',
    ]);

    expect($order->payments)->toHaveCount(2);
    expect($order->payments->sum('amount'))->toEqual(100000.00);
    expect($order->items->first()->product->id)->toBe($product->id);
});

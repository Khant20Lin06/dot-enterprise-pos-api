<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\PricingAndPromotions\Models\PriceList;
use App\Domains\PricingAndPromotions\Models\Promotion;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\Supplier;
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
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first()
        ?? StaffUser::first();

    Sanctum::actingAs($this->adminStaff);
});

/*
|--------------------------------------------------------------------------
| Module 1: Fleet Management CRUD & Relational Guards
|--------------------------------------------------------------------------
*/

it('creates, reads, updates, and guards fleet deletion against linked records', function () {
    // 1. Create Fleet
    $createResponse = $this->postJson('/api/v1/fleets', [
        'name' => 'Royal Express Logistics',
        'type' => 'Commercial Logistics',
        'credit_limit' => 5000000.00,
        'notes' => 'Priority corporate cargo fleet',
    ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Royal Express Logistics',
                'creditLimit' => 5000000.00,
            ],
        ]);

    $fleetId = $createResponse->json('data.id');

    // 2. Read single fleet
    $showResponse = $this->getJson("/api/v1/fleets/{$fleetId}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.name', 'Royal Express Logistics');

    // 3. Update fleet
    $updateResponse = $this->putJson("/api/v1/fleets/{$fleetId}", [
        'credit_limit' => 7000000.00,
    ]);
    $updateResponse->assertStatus(200);
    expect((float) $updateResponse->json('data.creditLimit'))->toBe(7000000.0);

    // 4. Link a vehicle to fleet to test relational guard
    $customer = Customer::first() ?? Customer::create(['name' => 'Fleet Cust', 'phone' => '0911112222']);
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id,
        'fleet_id' => $fleetId,
        'plate_number' => 'FLEET-' . time(),
        'make' => 'Hino',
        'model' => '500 Heavy Truck',
    ]);

    // Attempt delete: must fail with 422
    $blockedDelete = $this->deleteJson("/api/v1/fleets/{$fleetId}");
    $blockedDelete->assertStatus(422)
        ->assertJsonFragment(['success' => false]);

    // Unlink vehicle and delete successfully
    $vehicle->delete();
    $deleteResponse = $this->deleteJson("/api/v1/fleets/{$fleetId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true]);
});

/*
|--------------------------------------------------------------------------
| Module 2: Mechanics & Commission Disbursement
|--------------------------------------------------------------------------
*/

it('manages workshop mechanics and executes commission payout disbursement', function () {
    // 1. Register Mechanic
    $createResponse = $this->postJson('/api/v1/workshop/mechanics', [
        'name' => 'U Myint Thein',
        'phone' => '09988776655',
        'specialization' => 'Diesel Engine Overhaul',
        'commission_rate' => 15.00,
        'status' => 'Active',
    ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('data.name', 'U Myint Thein');

    $mechanicId = $createResponse->json('data.id');

    // 2. Create pending commission records
    $customer = Customer::first() ?? Customer::create(['name' => 'Mechanic Test Cust', 'phone' => '0922233344']);
    $vehicle = Vehicle::first() ?? Vehicle::create(['customer_id' => $customer->id, 'plate_number' => 'MEC-999', 'model' => 'Hilux']);
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-MEC-' . time(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'Completed / Invoiced',
        'complaint' => 'Engine inspection',
        'parts_total' => 0,
        'labor_fee' => 100000,
        'total_amount' => 100000,
    ]);

    $commission1 = MechanicCommission::create([
        'mechanic_id' => $mechanicId,
        'work_order_id' => $workOrder->id,
        'labor_amount' => 100000,
        'commission_rate' => 15.00,
        'earned_amount' => 15000,
        'status' => 'Pending',
    ]);

    $commission2 = MechanicCommission::create([
        'mechanic_id' => $mechanicId,
        'work_order_id' => $workOrder->id,
        'labor_amount' => 50000,
        'commission_rate' => 15.00,
        'earned_amount' => 7500,
        'status' => 'Pending',
    ]);

    // 3. List commissions
    $commissionsList = $this->getJson("/api/v1/workshop/mechanics/{$mechanicId}/commissions");
    $commissionsList->assertStatus(200)
        ->assertJsonStructure(['data']);

    // 4. Settle / Payout Commissions
    $payoutResponse = $this->postJson("/api/v1/workshop/mechanics/{$mechanicId}/commissions/payout", [
        'commission_ids' => [$commission1->id, $commission2->id],
        'notes' => 'End of month payroll disbursement',
    ]);

    $payoutResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'total_disbursed' => 22500.00,
                'commissions_count' => 2,
            ],
        ]);

    expect($commission1->fresh()->status)->toBe('Paid')
        ->and($commission2->fresh()->status)->toBe('Paid')
        ->and($commission1->fresh()->paid_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Module 3: Outsourced Workshop Parts
|--------------------------------------------------------------------------
*/

it('manages outsourced workshop third-party parts and machining orders', function () {
    $supplier = Supplier::first() ?? Supplier::create([
        'name' => 'Auto Lathe & Precision Works',
        'contact_person' => 'Ko Aung',
        'phone' => '09333555777',
    ]);

    $customer = Customer::first() ?? Customer::create(['name' => 'Outsource Cust', 'phone' => '0944433322']);
    $vehicle = Vehicle::first() ?? Vehicle::create(['customer_id' => $customer->id, 'plate_number' => 'OUT-111', 'model' => 'Land Cruiser']);
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-OUT-' . time(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'In Progress',
        'complaint' => 'Brake disc skim',
        'parts_total' => 0,
        'labor_fee' => 0,
        'total_amount' => 0,
    ]);

    // 1. Order Outsourced Part
    $createResponse = $this->postJson('/api/v1/workshop/outsourced-parts', [
        'work_order_id' => $workOrder->id,
        'supplier_id' => $supplier->id,
        'part_name' => 'Brake Disc Precision Resurfacing',
        'cost_price' => 25000.00,
        'bill_price' => 40000.00,
        'status' => 'Ordered',
        'payment_status' => 'Unpaid',
        'notes' => 'Front pair disc rotor',
    ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('data.partName', 'Brake Disc Precision Resurfacing');
    expect((float) $createResponse->json('data.profit'))->toBe(15000.0);

    $partId = $createResponse->json('data.id');

    // 2. Update status to received & fitted
    $updateResponse = $this->putJson("/api/v1/workshop/outsourced-parts/{$partId}", [
        'status' => 'Received & Fitted',
        'payment_status' => 'Paid',
    ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'Received & Fitted')
        ->assertJsonPath('data.paymentStatus', 'Paid');

    // 3. Delete
    $deleteResponse = $this->deleteJson("/api/v1/workshop/outsourced-parts/{$partId}");
    $deleteResponse->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Module 4: Purchase Returns (Return to Vendor - RTV)
|--------------------------------------------------------------------------
*/

it('processes purchase returns to vendor, decrements stock, and debits supplier payables', function () {
    $supplier = Supplier::create([
        'name' => 'Bando Belts Distributor',
        'contact_person' => 'Daw Htay',
        'phone' => '09777111222',
        'outstanding_balance' => 300000.00,
    ]);

    $warehouse = Warehouse::first() ?? Warehouse::create(['code' => 'WH-RTV', 'name' => 'RTV Warehouse', 'is_active' => true]);
    $category = Category::first() ?? Category::create(['code' => 'BELTS', 'name' => 'Belts']);
    $uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Alternator Serpentine Belt 6PK1150',
        'barcode' => 'BELT-6PK-' . time(),
        'sku' => 'SKU-BELT-' . time(),
        'retail_price' => 35000.00,
        'cost_price' => 20000.00,
        'stock' => 10,
        'is_labor' => false,
    ]);

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'stock_qty' => 10,
    ]);

    $purchase = Purchase::create([
        'po_no' => 'PO-RTV-' . time(),
        'supplier_id' => $supplier->id,
        'status' => 'Received',
        'total_amount' => 200000.00,
    ]);

    PurchaseItem::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'qty' => 10,
        'unit_price' => 20000.00,
        'subtotal' => 200000.00,
    ]);

    // Execute Return: 3 defective belts returned
    $returnResponse = $this->postJson('/api/v1/purchases/returns', [
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'qty' => 3,
        'warehouse_id' => $warehouse->id,
        'reason' => 'Factory defect - cracked rubber',
    ]);

    $returnResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'qty' => 3,
                'amount' => 60000.00,
                'status' => 'Claimed',
            ],
        ]);

    // Master stock decremented from 10 to 7
    expect((int) $product->fresh()->stock)->toBe(7)
        ->and((int) WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('stock_qty'))->toBe(7);

    // Supplier payable balance debited from 300,000 to 240,000 Ks
    expect((float) $supplier->fresh()->outstanding_balance)->toBe(240000.00);
});

/*
|--------------------------------------------------------------------------
| Module 5: Wholesale Price Lists & Promotions
|--------------------------------------------------------------------------
*/

it('manages wholesale tier price lists and promotional discount campaigns', function () {
    $product = Product::first();

    // 1. Create Wholesale Price List
    $priceListResponse = $this->postJson('/api/v1/pricing/price-lists', [
        'code' => 'PL-B2B-' . time(),
        'name' => 'B2B Wholesale Tier 1',
        'type' => 'WHOLESALE',
        'currency' => 'MMK',
        'is_active' => true,
        'items' => [
            [
                'product_id' => $product->id,
                'min_qty' => 10,
                'pricing_method' => 'FIXED',
                'value' => (float) $product->retail_price * 0.85,
            ],
        ],
    ]);

    $priceListResponse->assertStatus(201)
        ->assertJsonPath('data.type', 'WHOLESALE');

    $priceListId = $priceListResponse->json('data.id');

    // 2. Read single price list
    $this->getJson("/api/v1/pricing/price-lists/{$priceListId}")
        ->assertStatus(200)
        ->assertJsonPath('data.itemsCount', 1);

    // 3. Create Promotional Campaign
    $promoResponse = $this->postJson('/api/v1/pricing/promotions', [
        'code' => 'THADINGYUT-' . time(),
        'title' => 'Thadingyut Festive Discount 10%',
        'type' => 'percent',
        'value' => 10.00,
        'min_spend' => 50000.00,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'usage_limit' => 100,
        'is_active' => true,
        'target_tier' => 'All',
        'description' => '10% off for carts over 50,000 Ks',
    ]);

    $promoResponse->assertStatus(201)
        ->assertJsonPath('data.type', 'percent');
    expect((float) $promoResponse->json('data.value'))->toBe(10.0);

    $promoId = $promoResponse->json('data.id');

    // 4. Update and Delete Promo
    $this->putJson("/api/v1/pricing/promotions/{$promoId}", [
        'is_active' => false,
    ])->assertStatus(200)
      ->assertJsonPath('data.isActive', false);

    $this->deleteJson("/api/v1/pricing/promotions/{$promoId}")
        ->assertStatus(200);
});

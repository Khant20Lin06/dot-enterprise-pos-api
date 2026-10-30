<?php

use App\Domains\Inventory\Actions\AttachVehicleFitmentAction;
use App\Domains\Inventory\Actions\CreateProductUomConversionAction;
use App\Domains\Inventory\Actions\ResolveBarcodeAction;
use App\Domains\Inventory\Actions\SearchVehicleCompatiblePartsAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Domains\Inventory\Models\ProductVehicleFitment;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    //
});

/*
|--------------------------------------------------------------------------
| Multi-UOM / Packaging Units Conversion Engine Tests
|--------------------------------------------------------------------------
*/

it('creates packaging UOM conversion for a product with barcode and bulk pricing', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $product = Product::where('is_labor', false)->first();

    // Secondary UOM (Box)
    $boxUom = UnitOfMeasure::firstOrCreate(
        ['name' => 'Box'],
        ['description' => 'Packaging carton or retail box']
    );

    $action = app(CreateProductUomConversionAction::class);
    $conversion = $action->execute($product, [
        'uom_id' => $boxUom->id,
        'unit_name' => 'Box of 12',
        'conversion_factor' => 12.0000,
        'barcode' => '885000111222',
        'sku' => 'BOX-12-' . $product->barcode,
        'sell_price' => 110000.00,
        'cost_price' => 70000.00,
        'is_sale_default' => false,
    ], $admin->id);

    expect($conversion)->toBeInstanceOf(ProductUomConversion::class)
        ->and((float) $conversion->conversion_factor)->toBe(12.0)
        ->and((float) $conversion->sell_price)->toBe(110000.00)
        ->and($conversion->barcode)->toBe('885000111222');

    $this->assertDatabaseHas('product_uom_conversions', [
        'product_id' => $product->id,
        'uom_id' => $boxUom->id,
        'barcode' => '885000111222',
    ]);
});

it('blocks duplicate packaging UOM conversion for the same product and UOM', function () {
    $admin = StaffUser::first();
    $product = Product::where('is_labor', false)->first();

    $boxUom = UnitOfMeasure::firstOrCreate(['name' => 'Box']);

    $action = app(CreateProductUomConversionAction::class);
    $action->execute($product, [
        'uom_id' => $boxUom->id,
        'conversion_factor' => 12,
        'sell_price' => 100000,
    ], $admin->id);

    expect(fn () => $action->execute($product, [
        'uom_id' => $boxUom->id,
        'conversion_factor' => 24,
        'sell_price' => 190000,
    ], $admin->id))->toThrow(DomainException::class);
});

it('blocks creating a conversion rule for the product base UOM', function () {
    $admin = StaffUser::first();
    $product = Product::where('is_labor', false)->first();

    $action = app(CreateProductUomConversionAction::class);

    expect(fn () => $action->execute($product, [
        'uom_id' => $product->uom_id, // Base UOM
        'conversion_factor' => 1,
        'sell_price' => 10000,
    ], $admin->id))->toThrow(DomainException::class);
});

it('blocks zero or negative conversion factors', function () {
    $admin = StaffUser::first();
    $product = Product::where('is_labor', false)->first();
    $drumUom = UnitOfMeasure::firstOrCreate(['name' => 'Drum']);

    $action = app(CreateProductUomConversionAction::class);

    expect(fn () => $action->execute($product, [
        'uom_id' => $drumUom->id,
        'conversion_factor' => -5,
        'sell_price' => 50000,
    ], $admin->id))->toThrow(InvalidArgumentException::class);
});

it('resolves packaging UOM via barcode scanner action', function () {
    $product = Product::where('is_labor', false)->first();
    $canUom = UnitOfMeasure::firstOrCreate(['name' => 'Pack']);

    $boxBarcode = '885999888777';

    ProductUomConversion::create([
        'product_id' => $product->id,
        'uom_id' => $canUom->id,
        'unit_name' => 'Pack of 6',
        'conversion_factor' => 6.0000,
        'barcode' => $boxBarcode,
        'sell_price' => 45000.00,
    ]);

    $resolver = app(ResolveBarcodeAction::class);
    $result = $resolver->execute($boxBarcode);

    expect($result)->not->toBeNull()
        ->and($result['type'])->toBe('uom_conversion')
        ->and($result['unit_name'])->toBe('Pack of 6')
        ->and($result['conversion_factor'])->toBe(6.0)
        ->and($result['product']->id)->toBe($product->id);
});

it('completes POS checkout with packaging UOM and deducts proportional base inventory', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $product = Product::where('is_labor', false)->where('stock', '>=', 50)->first();
    $initialStock = $product->stock;

    $boxUom = UnitOfMeasure::firstOrCreate(['name' => 'Carton']);
    $conversion = ProductUomConversion::create([
        'product_id' => $product->id,
        'uom_id' => $boxUom->id,
        'unit_name' => 'Carton of 10',
        'conversion_factor' => 10.0000, // 1 Carton = 10 Base Pieces
        'sell_price' => 80000.00, // 80,000 Ks per Carton
    ]);

    // Sell 2 Cartons (Total base qty to deduct: 2 x 10 = 20 pieces)
    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'uom_conversion_id' => $conversion->id,
                'qty' => 2, // 2 Cartons
                'unit_price' => 80000.00,
            ],
        ],
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => 160000.00],
        ],
    ], $cashier->id);

    expect($order->total_amount)->toBe('160000.00')
        ->and($product->fresh()->stock)->toBe($initialStock - 20); // 20 base pieces deducted!

    $orderItem = $order->items->first();
    expect($orderItem->packaging_qty)->toBe('2.00')
        ->and($orderItem->qty)->toBe(20)
        ->and((float) $orderItem->conversion_factor)->toBe(10.0)
        ->and($orderItem->uom_conversion_id)->toBe($conversion->id);
});

/*
|--------------------------------------------------------------------------
| Automotive Vehicle Fitment & Compatibility Matrix Tests
|--------------------------------------------------------------------------
*/

it('attaches automotive vehicle compatibility rules to a product', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $product = Product::where('is_labor', false)->first();

    $action = app(AttachVehicleFitmentAction::class);
    $fitment = $action->execute($product, [
        'make' => 'Toyota',
        'model' => 'Vios',
        'year_from' => 2014,
        'year_to' => 2022,
        'engine_code' => '1NZ-FE',
        'chassis_code' => 'NCP150',
        'position' => 'Front Axle',
        'notes' => 'Fits all standard automatic transmission models',
    ], $admin->id);

    expect($fitment)->toBeInstanceOf(ProductVehicleFitment::class)
        ->and($fitment->make)->toBe('Toyota')
        ->and($fitment->model)->toBe('Vios')
        ->and($fitment->year_from)->toBe(2014)
        ->and($fitment->year_to)->toBe(2022);

    $this->assertDatabaseHas('product_vehicle_fitments', [
        'product_id' => $product->id,
        'make' => 'Toyota',
        'model' => 'Vios',
    ]);
});

it('searches compatible spare parts for a registered customer vehicle', function () {
    $product = Product::where('is_labor', false)->first();

    // Map product to Honda Fit (2015-2020)
    ProductVehicleFitment::create([
        'product_id' => $product->id,
        'make' => 'Honda',
        'model' => 'Fit',
        'year_from' => 2015,
        'year_to' => 2020,
        'position' => 'Engine Bay',
    ]);

    // Customer Vehicle: Honda Fit 2018
    $vehicle = Vehicle::first();
    $vehicle->update([
        'make' => 'Honda',
        'model' => 'Fit GP5',
        'year' => 2018,
    ]);

    $searchAction = app(SearchVehicleCompatiblePartsAction::class);
    $compatibleParts = $searchAction->execute($vehicle);

    expect($compatibleParts->pluck('id'))->toContain($product->id);
});

it('executes global catalog vehicle compatibility search by query parameters via API', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $product = Product::where('is_labor', false)->first();

    ProductVehicleFitment::create([
        'product_id' => $product->id,
        'make' => 'Mazda',
        'model' => 'Demio',
        'year_from' => 2010,
        'year_to' => 2016,
    ]);

    $response = $this->getJson('/api/v1/catalog/compatibility-search?make=Mazda&model=Demio&year=2014');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $partIds = collect($response->json('data'))->pluck('id');
    expect($partIds)->toContain($product->id);
});

it('manages packaging UOM conversions and vehicle fitments via REST API endpoints', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $product = Product::where('is_labor', false)->first();
    $drumUom = UnitOfMeasure::firstOrCreate(['name' => 'Drum']);

    // 1. Create packaging conversion via API
    $createResponse = $this->postJson("/api/v1/products/{$product->id}/uom-conversions", [
        'uom_id' => $drumUom->id,
        'unit_name' => 'Drum of 200 Liters',
        'conversion_factor' => 200.0000,
        'sell_price' => 1500000.00,
        'barcode' => '999888777666',
    ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.conversionFactor', 200);

    $conversionId = $createResponse->json('data.id');

    // 2. List conversions
    $listResponse = $this->getJson("/api/v1/products/{$product->id}/uom-conversions");
    $listResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    // 3. Delete conversion
    $deleteResponse = $this->deleteJson("/api/v1/products/{$product->id}/uom-conversions/{$conversionId}");
    $deleteResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(ProductUomConversion::find($conversionId))->toBeNull();
});

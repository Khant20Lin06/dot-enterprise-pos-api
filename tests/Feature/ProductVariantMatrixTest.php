<?php

use App\Domains\Inventory\Actions\CreateAttributeAction;
use App\Domains\Inventory\Actions\GenerateVariantMatrixAction;
use App\Domains\Inventory\Actions\ResolveBarcodeAction;
use App\Domains\Inventory\Models\Attribute;
use App\Domains\Inventory\Models\AttributeValue;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Exceptions\InsufficientStockException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

it('creates an attribute with values via API', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? StaffUser::first();
    Sanctum::actingAs($admin);

    $code = 'TEST_SIZE_' . time();

    $response = $this->postJson('/api/v1/attributes', [
        'code' => $code,
        'name' => 'Test Clothing Size',
        'description' => 'Apparel sizes for workshop uniforms',
        'values' => [
            ['value' => 'S', 'sort_order' => 1],
            ['value' => 'M', 'sort_order' => 2],
            ['value' => 'L', 'sort_order' => 3],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', $code)
        ->assertJsonCount(3, 'data.values');

    $this->assertDatabaseHas('attributes', ['code' => $code]);
    $this->assertDatabaseHas('attribute_values', ['value' => 'S']);
});

it('lists attributes and retrieves a specific attribute with values', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $action = app(CreateAttributeAction::class);
    $attr = $action->execute([
        'code' => 'TEST_COLOR_' . time(),
        'name' => 'Uniform Color',
        'values' => [
            ['value' => 'Navy Blue', 'color_hex' => '#000080'],
            ['value' => 'Racing Red', 'color_hex' => '#FF0000'],
        ],
    ]);

    $listResponse = $this->getJson('/api/v1/attributes');
    $listResponse->assertStatus(200)
        ->assertJsonPath('data.0.id', fn ($id) => is_numeric($id));

    $showResponse = $this->getJson("/api/v1/attributes/{$attr->id}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', $attr->code)
        ->assertJsonCount(2, 'data.values');
});

it('generates a 2x2 cartesian product variant matrix and synchronizes master stock', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $category = Category::first();
    $uom = UnitOfMeasure::first();

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Enterprise Mechanic Overall',
        'barcode' => 'OVERALL-' . time(),
        'cost_price' => 15000.00,
        'sell_price' => 35000.00,
        'stock' => 0,
        'min_stock' => 5,
        'is_labor' => false,
        'has_variants' => false,
    ]);

    $sizeAttr = Attribute::create(['code' => 'SIZE_' . time(), 'name' => 'Size']);
    $sizeS = AttributeValue::create(['attribute_id' => $sizeAttr->id, 'value' => 'S']);
    $sizeM = AttributeValue::create(['attribute_id' => $sizeAttr->id, 'value' => 'M']);

    $colorAttr = Attribute::create(['code' => 'CLR_' . time(), 'name' => 'Color']);
    $colorRed = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Red', 'color_hex' => '#FF0000']);
    $colorBlue = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Blue', 'color_hex' => '#0000FF']);

    $response = $this->postJson("/api/v1/products/{$product->id}/variants/matrix", [
        'attribute_value_ids' => [$sizeS->id, $sizeM->id, $colorRed->id, $colorBlue->id],
        'price' => 38000.00,
        'cost_price' => 18000.00,
        'initial_stock' => 10,
    ]);

    // 2 sizes * 2 colors = 4 variants
    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonCount(4, 'data');

    $product->refresh();
    expect($product->has_variants)->toBeTrue();
    // 4 variants * 10 initial_stock = 40 total stock
    expect($product->stock)->toBe(40);

    $variants = $product->variants;
    expect($variants)->toHaveCount(4);

    foreach ($variants as $variant) {
        expect($variant->price)->toEqual(38000.00);
        expect($variant->stock)->toBe(10);
        expect($variant->sku)->toStartWith("{$product->barcode}-");
        expect($variant->attributeValues)->toHaveCount(2);
    }
});

it('resolves barcodes correctly for both variant barcodes and master product barcodes', function () {
    $admin = StaffUser::first();
    Sanctum::actingAs($admin);

    $category = Category::first();
    $uom = UnitOfMeasure::first();

    $barcode = 'BARCODE-' . time();
    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Safety Gloves',
        'barcode' => $barcode,
        'cost_price' => 2000.00,
        'sell_price' => 5000.00,
        'stock' => 20,
        'is_labor' => false,
        'has_variants' => true,
    ]);

    $variantBarcode = "{$barcode}-V001";
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => "{$barcode}-L",
        'barcode' => $variantBarcode,
        'price' => 6000.00,
        'cost_price' => 2500.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    // 1. Scan variant barcode
    $varResponse = $this->getJson("/api/v1/products/barcode/{$variantBarcode}");
    $varResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('type', 'variant')
        ->assertJsonPath('data.barcode', $variantBarcode)
        ->assertJsonPath('product.id', $product->id);

    // 2. Scan master product barcode
    $prodResponse = $this->getJson("/api/v1/products/barcode/{$barcode}");
    $prodResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('type', 'product')
        ->assertJsonPath('data.barcode', $barcode);

    // 3. Scan non-existent barcode
    $notFoundResponse = $this->getJson("/api/v1/products/barcode/NON_EXISTENT_9999");
    $notFoundResponse->assertStatus(404)
        ->assertJsonPath('success', false);
});

it('deducts variant stock and master stock during pos checkout', function () {
    $cashier = StaffUser::first();
    $category = Category::first();
    $uom = UnitOfMeasure::first();

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Workshop Polo Shirt',
        'barcode' => 'SHIRT-' . time(),
        'cost_price' => 8000.00,
        'sell_price' => 15000.00,
        'stock' => 25,
        'is_labor' => false,
        'has_variants' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => "{$product->barcode}-XL-BLACK",
        'barcode' => "{$product->barcode}-V999",
        'price' => 16000.00,
        'cost_price' => 8500.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    $checkoutAction = app(ProcessCheckoutAction::class);

    // 1. Checkout 3 units of the variant
    $order = $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'qty' => 3,
                'unit_price' => 16000.00,
            ],
        ],
        'subtotal' => 48000.00,
        'total_amount' => 48000.00,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => 48000.00],
        ],
    ], $cashier->id);

    // Variant stock: 10 - 3 = 7
    expect($variant->fresh()->stock)->toBe(7);
    // Product master stock: 25 - 3 = 22
    expect($product->fresh()->stock)->toBe(22);

    // Check order item recorded variant_id
    $item = $order->items->first();
    expect($item->product_variant_id)->toBe($variant->id);
});

it('prevents overselling variant stock when quantity exceeds available variant inventory', function () {
    $cashier = StaffUser::first();
    $category = Category::first();
    $uom = UnitOfMeasure::first();

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Limited Edition Tool',
        'barcode' => 'TOOL-' . time(),
        'cost_price' => 50000.00,
        'sell_price' => 90000.00,
        'stock' => 10,
        'is_labor' => false,
        'has_variants' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => "{$product->barcode}-GOLD",
        'barcode' => "{$product->barcode}-VGOLD",
        'price' => 95000.00,
        'cost_price' => 52000.00,
        'stock' => 2, // only 2 in stock
        'is_active' => true,
    ]);

    $checkoutAction = app(ProcessCheckoutAction::class);

    // Attempting to buy 5 units when only 2 exist
    expect(function () use ($checkoutAction, $product, $variant, $cashier) {
        $checkoutAction->execute([
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'qty' => 5,
                    'unit_price' => 95000.00,
                ],
            ],
            'subtotal' => 475000.00,
            'total_amount' => 475000.00,
            'payment_splits' => [
                ['method' => 'Cash', 'amount' => 475000.00],
            ],
        ], $cashier->id);
    })->toThrow(InsufficientStockException::class);

    expect($variant->fresh()->stock)->toBe(2);
    expect($product->fresh()->stock)->toBe(10);
});

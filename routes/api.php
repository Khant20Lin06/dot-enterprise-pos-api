<?php

use App\Http\Controllers\Api\V1\AttributeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerCreditController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\PosSessionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Http\Controllers\Api\V1\StaffUserController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — DOT Enterprise ERP & POS API (V1)
|--------------------------------------------------------------------------
|
| Versioned RESTful API endpoints for Multi-counter POS, Workshop Job Cards,
| Inventory Catalog, and Customer Credit Debt Ledger.
|
*/

Route::prefix('v1')->group(function () {
    // Public Authentication Endpoints (Hardened with IP Throttling)
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/verify-pin', [AuthController::class, 'verifyPin'])->middleware('throttle:10,1');
    });

    // Protected Endpoints (Requires Sanctum Bearer Token)
    Route::middleware('auth:sanctum')->group(function () {
        // Authenticated Staff
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        // Catalog & Inventory
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index']);
            Route::get('/barcode/{barcode}', [ProductController::class, 'lookupBarcode']);
            Route::get('/{id}', [ProductController::class, 'show']);
            Route::post('/{id}/barcode', [ProductController::class, 'barcode']);
            Route::get('/{id}/variants', [ProductController::class, 'variants']);
            Route::post('/{id}/variants/matrix', [ProductController::class, 'generateMatrix'])
                ->middleware('permission:inventory,create');
        });

        // Variant Attributes Master (Size, Color, etc.)
        Route::prefix('attributes')->group(function () {
            Route::get('/', [AttributeController::class, 'index']);
            Route::post('/', [AttributeController::class, 'store'])
                ->middleware('permission:inventory,create');
            Route::get('/{id}', [AttributeController::class, 'show']);
        });

        // POS Operations & Orders
        Route::prefix('pos')->group(function () {
            Route::post('/checkout', [PosController::class, 'checkout']);
            Route::get('/orders', [PosController::class, 'orders']);
            Route::get('/orders/{id}', [PosController::class, 'showOrder']);
            Route::post('/orders/{id}/reprint', [PosController::class, 'reprintReceipt']);

            // Parked Carts (Held Orders)
            Route::get('/held-orders', [PosController::class, 'listHeldOrders']);
            Route::post('/held-orders', [PosController::class, 'parkOrder']);
            Route::delete('/held-orders/{id}', [PosController::class, 'deleteHeldOrder']);

            // Cash Register Shifts (Sessions) & Blind Count Reconciliation
            Route::prefix('sessions')->group(function () {
                Route::get('/', [PosSessionController::class, 'index']);
                Route::get('/current', [PosSessionController::class, 'current']);
                Route::post('/open', [PosSessionController::class, 'open']);
                Route::get('/{id}', [PosSessionController::class, 'show']);
                Route::post('/{id}/close', [PosSessionController::class, 'close']);
                Route::get('/{id}/x-report', [PosSessionController::class, 'xReport']);
                Route::get('/{id}/z-report', [PosSessionController::class, 'zReport']);
            });
        });

        // Payment Gateways & Dynamic QR
        Route::prefix('payments')->group(function () {
            Route::post('/generate-qr', [PaymentGatewayController::class, 'generateQr']);
            Route::get('/transactions/{transactionNo}', [PaymentGatewayController::class, 'status']);
            Route::post('/simulate-webhook', [PaymentGatewayController::class, 'simulateWebhook']);
        });

        // Sales Returns & Refunds
        Route::prefix('sales-returns')->group(function () {
            Route::get('/', [SalesReturnController::class, 'index']);
            Route::post('/', [SalesReturnController::class, 'store'])
                ->middleware('permission:pos,create');
            Route::get('/{id}', [SalesReturnController::class, 'show']);
        });

        // Automotive Workshop & Job Cards
        Route::prefix('workshop')->group(function () {
            Route::get('/work-orders', [WorkOrderController::class, 'index']);
            Route::post('/work-orders', [WorkOrderController::class, 'store']);
            Route::get('/work-orders/{id}', [WorkOrderController::class, 'show']);
            Route::post('/work-orders/{id}/convert-to-invoice', [WorkOrderController::class, 'convertToInvoice'])
                ->middleware('permission:workshop,approveJobCard');
        });

        // Customers & Credit Ledger
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerCreditController::class, 'index']);
            Route::get('/{id}', [CustomerCreditController::class, 'show']);
            Route::post('/{id}/settle-credit', [CustomerCreditController::class, 'settleCredit'])
                ->middleware('permission:finances,approveCreditLimit');
            Route::get('/{id}/ledger', [CustomerCreditController::class, 'ledger']);
        });

        // Executive Reports & Analytics (Restricted to Finance/Admin)
        Route::prefix('reports')->group(function () {
            Route::get('/daily-summary', [ReportController::class, 'dailySummary'])
                ->middleware('permission:finances,view');
        });

        // Roles & Permissions Management (Restricted to settings.manageRoles)
        Route::prefix('roles')->group(function () {
            Route::get('/permissions-catalog', [RoleController::class, 'permissionsCatalog']);
            Route::get('/', [RoleController::class, 'index']);
            Route::post('/', [RoleController::class, 'store'])
                ->middleware('permission:settings,manageRoles');
            Route::get('/{id}', [RoleController::class, 'show']);
            Route::put('/{id}', [RoleController::class, 'update'])
                ->middleware('permission:settings,manageRoles');
            Route::delete('/{id}', [RoleController::class, 'destroy'])
                ->middleware('permission:settings,manageRoles');
        });

        // Staff Users Management (Restricted to settings.manageRoles)
        Route::prefix('staff')->group(function () {
            Route::get('/', [StaffUserController::class, 'index']);
            Route::post('/', [StaffUserController::class, 'store'])
                ->middleware('permission:settings,manageRoles');
            Route::get('/{id}', [StaffUserController::class, 'show']);
            Route::put('/{id}', [StaffUserController::class, 'update'])
                ->middleware('permission:settings,manageRoles');
            Route::patch('/{id}/status', [StaffUserController::class, 'updateStatus'])
                ->middleware('permission:settings,manageRoles');
            Route::delete('/{id}', [StaffUserController::class, 'destroy'])
                ->middleware('permission:settings,manageRoles');
        });

        // Warehouses & Physical Branches
        Route::prefix('warehouses')->group(function () {
            Route::get('/', [WarehouseController::class, 'index']);
            Route::post('/', [WarehouseController::class, 'store'])
                ->middleware('permission:inventory,create');
            Route::get('/{id}', [WarehouseController::class, 'show']);
            Route::put('/{id}', [WarehouseController::class, 'update'])
                ->middleware('permission:inventory,edit');
            Route::get('/{id}/stocks', [WarehouseController::class, 'stocks']);
        });

        // Stock Transfers (Two-step Dispatch & Receive)
        Route::prefix('stock-transfers')->group(function () {
            Route::get('/', [StockTransferController::class, 'index']);
            Route::post('/', [StockTransferController::class, 'store'])
                ->middleware('permission:inventory,adjustStock');
            Route::get('/{id}', [StockTransferController::class, 'show']);
            Route::post('/{id}/dispatch', [StockTransferController::class, 'dispatchTransfer'])
                ->middleware('permission:inventory,adjustStock');
            Route::post('/{id}/receive', [StockTransferController::class, 'receiveTransfer'])
                ->middleware('permission:inventory,adjustStock');
            Route::post('/{id}/cancel', [StockTransferController::class, 'cancelTransfer'])
                ->middleware('permission:inventory,adjustStock');
        });
    });
});

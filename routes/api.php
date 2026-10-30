<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerCreditController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SalesReturnController;
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
            Route::get('/{id}', [ProductController::class, 'show']);
            Route::post('/{id}/barcode', [ProductController::class, 'barcode']);
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
    });
});

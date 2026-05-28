<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashRegisterSessionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderStatusTransitionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableQrExportController;
use App\Http\Controllers\TableSplitBillController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WaitingTimeController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::prefix('public')->group(function () {
    Route::post('/table-sessions', [TableController::class, 'createPublicSession'])->middleware('throttle:public-table-session');
    Route::get('/tables/availability', [TableController::class, 'availability']);
    Route::get('/tables/{uuid}', [TableController::class, 'showPublic']);
    Route::get('/menu', [ProductController::class, 'publicIndex']);
    Route::post('/tables/{uuid}/orders', [OrderController::class, 'storePublicForTable'])->middleware('throttle:public-table-action');
    Route::post('/reservations', [ReservaController::class, 'storePublic'])->middleware('throttle:public-table-action');
    Route::get('/reservations/availability', [ReservaController::class, 'checkAvailability']);
    Route::post('/reservations/history', [ReservaController::class, 'publicHistory'])->middleware('throttle:public-table-action');
    Route::post('/reservations/{trackingToken}/proof', [ReservaController::class, 'replacePublicProof'])->middleware('throttle:public-table-action');
    Route::post('/tables/{uuid}/call', [TableController::class, 'callPublic'])->middleware('throttle:public-table-action');
    Route::post('/tables/{uuid}/payment', [TableController::class, 'paymentPublic'])->middleware('throttle:public-table-action');
    Route::post('/payments/{payment}/mock-checkout/session', [PaymentController::class, 'createPublicMockCheckoutSession'])->middleware('throttle:mock-checkout');
    Route::post('/payments/mock-checkout/submit', [PaymentController::class, 'submitMockCheckout'])->middleware('throttle:mock-checkout');
    Route::get('/loyalty/points', [LoyaltyController::class, 'getPoints']);
    Route::post('/loyalty/redeem', [LoyaltyController::class, 'redeemPoints'])->middleware('throttle:public-table-action');
});

$protectedRoutes = function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/tables', [TableController::class, 'index']);
    Route::get('/tables/{mesa}', [TableController::class, 'show']);
    Route::put('/tables/{mesa}', [TableController::class, 'update']);
    Route::post('/tables/{mesa}/assign-waiter', [TableController::class, 'assignWaiter']);
    Route::post('/tables/{mesa}/calls/acknowledge', [TableController::class, 'acknowledgeCall']);
    Route::post('/tables/{mesa}/calls/resolve', [TableController::class, 'resolveCall']);
    Route::post('/tables/{mesa}/occupy', [TableController::class, 'occupy']);
    Route::post('/tables/{mesa}/free', [TableController::class, 'free']);
    Route::get('/tables/{mesa}/qr.svg', [TableQrExportController::class, 'svg']);
    Route::get('/tables/{mesa}/qr.pdf', [TableQrExportController::class, 'pdf']);
    Route::post('/tables/qr/bulk-export', [TableQrExportController::class, 'bulkExport']);
    Route::get('/tables/{mesa}/split-bill', [TableSplitBillController::class, 'show']);
    Route::post('/tables/{mesa}/split-bill/initialize', [TableSplitBillController::class, 'initialize']);
    Route::post('/tables/{mesa}/split-bill/accounts', [TableSplitBillController::class, 'createAccount']);
    Route::patch('/tables/{mesa}/split-bill/accounts/{account}', [TableSplitBillController::class, 'updateAccount']);
    Route::post('/tables/{mesa}/split-bill/allocations', [TableSplitBillController::class, 'mutateAllocations']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::get('/ingredients/low-stock', [IngredientController::class, 'lowStock']);
    Route::get('/ingredients/expiring', [IngredientController::class, 'expiring']);
    Route::apiResource('ingredients', IngredientController::class);
    Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{pedido}', [OrderController::class, 'show']);
    Route::put('/orders/{pedido}', [OrderController::class, 'update']);
    Route::post('/orders/{order}/status-transitions', [OrderStatusTransitionController::class, 'store']);

    Route::post('/payments/intents', [PaymentController::class, 'createIntent']);
    Route::post('/payments/{payment}/mark-client-paid', [PaymentController::class, 'markClientPaid']);
    Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm']);
    Route::post('/payments/{payment}/mock-checkout/session', [PaymentController::class, 'createMockCheckoutSession'])->middleware('throttle:mock-checkout');
    Route::post('/payments/mock-checkout/submit', [PaymentController::class, 'submitMockCheckout'])->middleware('throttle:mock-checkout');

    Route::post('/cash-register-sessions/open', [CashRegisterSessionController::class, 'open']);
    Route::post('/cash-register-sessions/{session}/close', [CashRegisterSessionController::class, 'close']);

    Route::apiResource('users', UserController::class);
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::get('/roles', fn () => \App\Models\Rol::all());

    Route::apiResource('reviews', ReviewController::class);
    Route::get('/reservations/agenda', [ReservaController::class, 'agenda']);
    Route::get('/reservations/review-queue', [ReservaController::class, 'reviewQueue']);
    Route::get('/reservations/active', [ReservaController::class, 'active']);
    Route::post('/reservations/{reserva}/review', [ReservaController::class, 'review']);
    Route::post('/reservations/{reserva}/operational-status', [ReservaController::class, 'updateOperationalStatus']);
    Route::apiResource('reservations', ReservaController::class);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/waiting-time', [WaitingTimeController::class, 'index']);
    Route::get('/customers/top', [CustomerAnalyticsController::class, 'topCustomers']);
    Route::get('/customers/retention', [CustomerAnalyticsController::class, 'customerRetention']);

    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::get('/settings/{group}', [SettingController::class, 'getByGroup']);

    Route::get('/events', [EventStreamController::class, 'stream']);
};

Route::middleware('auth:sanctum')
    ->name('legacy.')
    ->group($protectedRoutes);

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->name('v1.')
    ->group($protectedRoutes);

<?php

use App\Http\Controllers\BecomeAgentController;
use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\JoinUsController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\PaymentController;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('/become_an_agent', function () {
        return Inertia::render('become_an_agent');
    })->name('become_an_agent');

Route::middleware(['auth'])->group(function () {
    Route::post('/become_an_agent', [BecomeAgentController::class, 'update'])->name('become_an_agent.update');
});
Route::get('/agent/callback', [BecomeAgentController::class, 'handleAgentCallback'])->name('agent.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/wallet', [WalletController::class, 'index'])->name('dashboard.wallet');
    Route::get('/dashboard/joinUs', [JoinUsController::class, 'index'])->name('dashboard.joinUs');
    Route::get('/dashboard/orders', [OrdersController::class, 'index'])->name('dashboard.orders');
    Route::get('/dashboard/transactions', [TransactionsController::class, 'index'])->name('dashboard.transactions');
    Route::get('/dashboard/api-docs', [\App\Http\Controllers\ApiDocsController::class, 'index'])->name('dashboard.api-docs');
    Route::post('/api/generate-key', [\App\Http\Controllers\ApiDocsController::class, 'generateApiKey'])->name('api.docs.generate-key');
    
    // Cart routes
    Route::post('/add-to-cart', [CartController::class, 'store'])->name('add.to.cart');
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::delete('/cart/{cart}', [CartController::class, 'destroy'])->name('remove.from.cart');

    // Wallet balance route
    Route::post('/dashboard/wallet/add', [DashboardController::class, 'addToWallet'])->name('dashboard.wallet.add');
    Route::get('/wallet/callback', [DashboardController::class, 'handleWalletCallback'])->name('wallet.callback');
    Route::post('/dashboard/wallet/verify', [WalletController::class, 'verifyPayment'])->name('dashboard.wallet.verify');

    // ❌ REMOVED THE DUPLICATE ADMIN ROUTE FROM HERE
    // Route::get('/admin/dashboard', [\App\Http\Controllers\AdminDashboardController::class, 'index'])->name('admin.dashboard');
});

// Checkout route
Route::middleware(['auth'])->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/place_order', [OrdersController::class, 'checkout'])->name('checkout.process');
});

// Admin routes - This is the correct group with role middleware
Route::middleware(['auth', 'verified', 'role:admin'])->name('admin.')->group(function () {
    Route::get('admin/dashboard', [\App\Http\Controllers\AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('admin/users', [\App\Http\Controllers\AdminDashboardController::class, 'users'])->name('users');
    Route::post('admin/users', [\App\Http\Controllers\AdminDashboardController::class, 'storeUser'])->name('users.store');
    Route::put('admin/users/{user}', [\App\Http\Controllers\AdminDashboardController::class, 'updateUserRole'])->name('users.updateRole');
    Route::delete('admin/users/{user}', [\App\Http\Controllers\AdminDashboardController::class, 'deleteUser'])->name('users.delete');
    Route::post('admin/users/{user}/credit', [\App\Http\Controllers\AdminDashboardController::class, 'creditWallet'])->name('users.credit');
    Route::post('admin/users/{user}/debit', [\App\Http\Controllers\AdminDashboardController::class, 'debitWallet'])->name('users.debit');
    Route::get('admin/products', [\App\Http\Controllers\AdminDashboardController::class, 'products'])->name('products');
    Route::post('admin/products', [\App\Http\Controllers\AdminDashboardController::class, 'storeProduct'])->name('products.store');
    Route::put('admin/products/{product}', [\App\Http\Controllers\AdminDashboardController::class, 'updateProduct'])->name('products.update');
    Route::delete('admin/products/{product}', [\App\Http\Controllers\AdminDashboardController::class, 'deleteProduct'])->name('products.delete');
    Route::get('admin/orders', [\App\Http\Controllers\AdminDashboardController::class, 'orders'])->name('orders');
    Route::delete('admin/orders/{order}', [\App\Http\Controllers\AdminDashboardController::class, 'deleteOrder'])->name('orders.delete');
    Route::put('admin/orders/{order}/status', [\App\Http\Controllers\AdminDashboardController::class, 'updateOrderStatus'])->name('orders.updateStatus');
    Route::put('admin/orders/bulk-status', [\App\Http\Controllers\AdminDashboardController::class, 'bulkUpdateOrderStatus'])->name('orders.bulkUpdateStatus');
    Route::get('admin/transactions', [\App\Http\Controllers\AdminDashboardController::class, 'transactions'])->name('transactions');
    Route::get('admin/users/{user}/transactions', [\App\Http\Controllers\AdminDashboardController::class, 'userTransactions'])->name('users.transactions');
    Route::post('admin/orders/export', [\App\Http\Controllers\AdminDashboardController::class, 'exportOrders'])->name('orders.export');
    Route::post('admin/api/toggle', [\App\Http\Controllers\AdminDashboardController::class, 'toggleApi'])->name('api.toggle');
});

// Paystack payment routes
Route::get('/payment', function () {
    return view('payment');
})->name('payment');
Route::post('/payment/initialize', [PaymentController::class, 'initializePayment'])->name('payment.initialize');
Route::get('/payment/callback', [PaymentController::class, 'handleCallback'])->name('payment.callback');
Route::get('/payment/success', function () { return 'Payment Successful!'; })->name('payment.success');
Route::get('/payment/failed', function () { return 'Payment Failed!'; })->name('payment.failed');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
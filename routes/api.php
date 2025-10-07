<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrdersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Test route
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working',
        'timestamp' => now()
    ]);
});

// Public routes with rate limiting
Route::middleware('throttle:30,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
});

// Protected routes
Route::middleware('api.key.auth')->group(function () {
    // Order routes
    Route::post('/orders', [OrdersController::class, 'createOrder']);
    Route::get('/orders', [OrdersController::class, 'getOrders']);
    Route::get('/orders/{id}', [OrdersController::class, 'getOrder']);
    
    // Product routes
    Route::get('/products', [OrdersController::class, 'getProducts']);
});
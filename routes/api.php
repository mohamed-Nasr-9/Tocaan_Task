<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('auth.register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');
        Route::middleware('auth:api')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        });
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::apiResource('orders', OrderController::class);
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
        Route::get('/orders/{order}/payments', [PaymentController::class, 'forOrder'])->whereNumber('order')->name('orders.payments.index');
        Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->whereNumber('order')->name('orders.payments.store');
        Route::get('/orders/{order}/payments/{payment}', [PaymentController::class, 'showForOrder'])
            ->whereNumber(['order', 'payment'])
            ->name('orders.payments.show');
    });
});

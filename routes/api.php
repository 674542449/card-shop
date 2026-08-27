<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::middleware(\App\Http\Middleware\ApiTokenAuth::class)->group(function () {
    Route::get('/products', [Api\ProductController::class, 'index']);
    Route::get('/products/{id}', [Api\ProductController::class, 'show']);
    Route::post('/orders', [Api\OrderController::class, 'create']);
    Route::get('/orders/{order_no}', [Api\OrderController::class, 'show']);
});

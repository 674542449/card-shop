<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

// bootstrap/app.php never calls throttleApi() and no RateLimiter::for is registered
// anywhere, so this group carries no limiter of its own — the throttles below are the
// only ones these routes get.
Route::middleware(\App\Http\Middleware\ApiTokenAuth::class)->group(function () {
    Route::get('/products', [Api\ProductController::class, 'index']);
    Route::get('/products/{id}', [Api\ProductController::class, 'show']);
    // Creating an order locks cards out of sale, the same as the web checkout, which
    // is capped at 5/min. This path additionally has no per-IP pending-order cap.
    Route::post('/orders', [Api\OrderController::class, 'create'])
        ->middleware('throttle:20,1');
    // Runs a bcrypt against a buyer-chosen query password and returns card secrets on
    // success. Unlimited, a token holder who had seen one order number and email — a
    // forwarded receipt, a support ticket — could guess the password at full speed.
    Route::get('/orders/{order_no}', [Api\OrderController::class, 'show'])
        ->middleware('throttle:30,1');
});

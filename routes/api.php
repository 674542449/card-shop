<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

// bootstrap/app.php never calls throttleApi() and no RateLimiter::for is registered
// anywhere, so this group carries no limiter of its own — the throttles below are the
// only ones these routes get.
Route::middleware(\App\Http\Middleware\ApiTokenAuth::class)->group(function () {
    Route::get('/products', [Api\ProductController::class, 'index']);
    Route::get('/products/{id}', [Api\ProductController::class, 'show']);
    // Per-IP throttle supplements the per-token rate and reservation quotas.
    Route::post('/orders', [Api\OrderController::class, 'create'])
        ->middleware('throttle:20,1,api-order-create');
    // Runs a bcrypt against a buyer-chosen query password and returns card secrets on
    // success. Unlimited, a token holder who had seen one order number and email — a
    // forwarded receipt, a support ticket — could guess the password at full speed.
    Route::post('/orders/{order_no}/query', [Api\OrderController::class, 'show'])
        ->middleware('throttle:30,1,api-order-query');
    Route::post('/orders/{orderNo}/cancel', [Api\OrderController::class, 'cancel'])->middleware('throttle:10,1,api-order-cancel');
    Route::get('/orders/{order_no}', fn () => response()->json([
        'message' => '查单接口已改为 POST /api/v1/orders/{order_no}/query，请在请求正文传递 email 和 query_password。',
    ], 405)->header('Allow', 'POST')->header('Cache-Control', 'no-store'));
});

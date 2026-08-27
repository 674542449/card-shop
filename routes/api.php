<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded with the 'api/v1' prefix by bootstrap/app.php.
| Token authentication is enforced via a closure middleware that checks
| the Authorization header against the api_tokens table.
|
*/

Route::middleware(function (Request $request, \Closure $next) {
    $token = $request->bearerToken();

    if (!$token) {
        return response()->json(['error' => 'Unauthorized. API token required.'], 401);
    }

    $apiToken = \Illuminate\Support\Facades\DB::table('api_tokens')
        ->where('token', hash('sha256', $token))
        ->where('is_active', true)
        ->first();

    if (!$apiToken) {
        return response()->json(['error' => 'Invalid or inactive API token.'], 401);
    }

    // Update last used timestamp
    \Illuminate\Support\Facades\DB::table('api_tokens')
        ->where('id', $apiToken->id)
        ->update(['last_used_at' => now()]);

    return $next($request);
})->group(function () {
    Route::get('/products', [Api\ProductController::class, 'index']);
    Route::get('/products/{id}', [Api\ProductController::class, 'show']);
    Route::post('/orders', [Api\OrderController::class, 'create']);
    Route::get('/orders/{order_no}', [Api\OrderController::class, 'show']);
});

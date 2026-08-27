<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Front;
use App\Http\Controllers\Api\Admin as ApiAdmin;

/*
|--------------------------------------------------------------------------
| Frontend Routes
|--------------------------------------------------------------------------
*/

// Payment callbacks (no CSRF, no blacklist check — external payment providers)
Route::post('/payment/epay/notify', [Front\PaymentController::class, 'epayNotify']);
Route::post('/payment/epusdt/notify', [Front\PaymentController::class, 'epusdtNotify']);

Route::middleware('check.blacklist')->group(function () {
    // Home
    Route::get('/', [Front\HomeController::class, 'index']);

    // Products
    Route::get('/category/{slug}', [Front\ProductController::class, 'category']);
    Route::get('/product/{slug}', [Front\ProductController::class, 'show']);

    // Orders
    Route::post('/order/create', [Front\OrderController::class, 'create'])->middleware('turnstile');
    Route::get('/order/pay/{order_no}', [Front\OrderController::class, 'pay']);
    Route::get('/order/query', [Front\OrderController::class, 'queryForm']);
    Route::post('/order/query', [Front\OrderController::class, 'query'])->middleware('turnstile');
    Route::get('/order/detail/{order_no}', [Front\OrderController::class, 'detail']);
    Route::post('/order/verify', [Front\OrderController::class, 'verify']);

    // Payment return (user-facing, stays in blacklist group)
    Route::get('/payment/epay/return', [Front\PaymentController::class, 'epayReturn']);

    // Articles
    Route::get('/articles', [Front\ArticleController::class, 'index']);
    Route::get('/articles/category/{slug}', [Front\ArticleController::class, 'category']);
    Route::get('/articles/{slug}', [Front\ArticleController::class, 'show']);

    // Sitemap
    Route::get('/sitemap.xml', [Front\SitemapController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Admin API Routes (JSON, session auth)
|--------------------------------------------------------------------------
*/

Route::prefix('api/admin')->group(function () {
    Route::post('/login', [ApiAdmin\AuthController::class, 'login']);

    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [ApiAdmin\AuthController::class, 'logout']);
        Route::get('/me', [ApiAdmin\AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [ApiAdmin\DashboardController::class, 'index']);

        // Categories
        Route::get('/categories', [ApiAdmin\CategoryController::class, 'index']);
        Route::post('/categories', [ApiAdmin\CategoryController::class, 'store']);
        Route::put('/categories/{category}', [ApiAdmin\CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [ApiAdmin\CategoryController::class, 'destroy']);

        // Products
        Route::get('/products', [ApiAdmin\ProductController::class, 'index']);
        Route::post('/products', [ApiAdmin\ProductController::class, 'store']);
        Route::get('/products/{product}', [ApiAdmin\ProductController::class, 'show']);
        Route::put('/products/{product}', [ApiAdmin\ProductController::class, 'update']);
        Route::delete('/products/{product}', [ApiAdmin\ProductController::class, 'destroy']);

        // Cards
        Route::get('/products/{product}/cards', [ApiAdmin\CardController::class, 'index']);
        Route::post('/products/{product}/cards/import', [ApiAdmin\CardController::class, 'import']);
        Route::delete('/cards/batch-destroy', [ApiAdmin\CardController::class, 'batchDestroy']);
        Route::delete('/cards/{card}', [ApiAdmin\CardController::class, 'destroy']);

        // Orders
        Route::get('/orders', [ApiAdmin\OrderController::class, 'index']);
        Route::get('/orders/export', [ApiAdmin\OrderController::class, 'export']);
        Route::get('/orders/{order}', [ApiAdmin\OrderController::class, 'show']);
        Route::post('/orders/{order}/close', [ApiAdmin\OrderController::class, 'close']);
        Route::post('/orders/{order}/paid', [ApiAdmin\OrderController::class, 'markPaid']);
        Route::post('/orders/{order}/resend', [ApiAdmin\OrderController::class, 'resend']);

        // Articles
        Route::get('/articles', [ApiAdmin\ArticleController::class, 'index']);
        Route::post('/articles', [ApiAdmin\ArticleController::class, 'store']);
        Route::get('/articles/{article}', [ApiAdmin\ArticleController::class, 'show']);
        Route::put('/articles/{article}', [ApiAdmin\ArticleController::class, 'update']);
        Route::delete('/articles/{article}', [ApiAdmin\ArticleController::class, 'destroy']);

        // Article Categories
        Route::get('/article-categories', [ApiAdmin\ArticleCategoryController::class, 'index']);
        Route::post('/article-categories', [ApiAdmin\ArticleCategoryController::class, 'store']);
        Route::put('/article-categories/{articleCategory}', [ApiAdmin\ArticleCategoryController::class, 'update']);
        Route::delete('/article-categories/{articleCategory}', [ApiAdmin\ArticleCategoryController::class, 'destroy']);

        // Coupons
        Route::get('/coupons', [ApiAdmin\CouponController::class, 'index']);
        Route::post('/coupons', [ApiAdmin\CouponController::class, 'store']);
        Route::put('/coupons/{coupon}', [ApiAdmin\CouponController::class, 'update']);
        Route::delete('/coupons/{coupon}', [ApiAdmin\CouponController::class, 'destroy']);

        // Blacklists
        Route::get('/blacklists', [ApiAdmin\BlacklistController::class, 'index']);
        Route::post('/blacklists', [ApiAdmin\BlacklistController::class, 'store']);
        Route::delete('/blacklists/{blacklist}', [ApiAdmin\BlacklistController::class, 'destroy']);

        // Logs
        Route::get('/logs', [ApiAdmin\LogController::class, 'index']);

        // Settings
        Route::get('/settings', [ApiAdmin\SettingController::class, 'index']);
        Route::post('/settings', [ApiAdmin\SettingController::class, 'update']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin SPA (React + Ant Design Pro)
|--------------------------------------------------------------------------
| All /admin/* GET routes serve the SPA shell. The React Router handles
| client-side routing. Data is served via /api/admin/* routes above.
*/

Route::get('/admin/{any?}', function () {
    return view('admin.spa');
})->where('any', '.*');

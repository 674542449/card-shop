<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Front;
use App\Http\Controllers\Admin;

/*
|--------------------------------------------------------------------------
| Frontend Routes
|--------------------------------------------------------------------------
*/

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

    // Payment callbacks
    Route::post('/payment/epay/notify', [Front\PaymentController::class, 'epayNotify']);
    Route::get('/payment/epay/return', [Front\PaymentController::class, 'epayReturn']);
    Route::post('/payment/epusdt/notify', [Front\PaymentController::class, 'epusdtNotify']);

    // Articles
    Route::get('/articles', [Front\ArticleController::class, 'index']);
    Route::get('/articles/category/{slug}', [Front\ArticleController::class, 'category']);
    Route::get('/articles/{slug}', [Front\ArticleController::class, 'show']);

    // Sitemap
    Route::get('/sitemap.xml', [Front\SitemapController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function () {
    // Auth (no admin.auth middleware)
    Route::get('/login', [Admin\AuthController::class, 'showLogin']);
    Route::post('/login', [Admin\AuthController::class, 'login']);
    Route::post('/logout', [Admin\AuthController::class, 'logout']);

    // Protected admin routes
    Route::middleware('admin.auth')->group(function () {
        // Dashboard
        Route::get('/', [Admin\DashboardController::class, 'index']);

        // Categories
        Route::resource('categories', Admin\CategoryController::class);

        // Products
        Route::resource('products', Admin\ProductController::class);

        // Cards
        Route::get('/products/{product}/cards', [Admin\CardController::class, 'index']);
        Route::post('/products/{product}/cards/import', [Admin\CardController::class, 'import']);
        Route::delete('/cards/{card}', [Admin\CardController::class, 'destroy']);
        Route::delete('/cards/batch-destroy', [Admin\CardController::class, 'batchDestroy']);

        // Orders
        Route::get('/orders', [Admin\OrderController::class, 'index']);
        Route::get('/orders/export', [Admin\OrderController::class, 'export']);
        Route::get('/orders/{order}', [Admin\OrderController::class, 'show']);
        Route::post('/orders/{order}/resend', [Admin\OrderController::class, 'resend']);
        Route::post('/orders/{order}/close', [Admin\OrderController::class, 'close']);
        Route::post('/orders/{order}/paid', [Admin\OrderController::class, 'markPaid']);

        // Articles
        Route::resource('articles', Admin\ArticleController::class);

        // Article Categories
        Route::resource('article-categories', Admin\ArticleCategoryController::class);

        // Coupons
        Route::resource('coupons', Admin\CouponController::class);

        // Blacklists
        Route::get('/blacklists', [Admin\BlacklistController::class, 'index']);
        Route::post('/blacklists', [Admin\BlacklistController::class, 'store']);
        Route::delete('/blacklists/{blacklist}', [Admin\BlacklistController::class, 'destroy']);

        // Logs
        Route::get('/logs', [Admin\LogController::class, 'index']);

        // Settings
        Route::get('/settings', [Admin\SettingController::class, 'index']);
        Route::post('/settings', [Admin\SettingController::class, 'update']);
    });
});

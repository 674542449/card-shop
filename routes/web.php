<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Front;
use App\Http\Controllers\Api\Admin as ApiAdmin;

/*
|--------------------------------------------------------------------------
| Frontend Routes
|--------------------------------------------------------------------------
*/

// Payment callbacks (no CSRF, no blacklist check — external payment providers).
Route::get('/{indexnowKey}.txt', function (string $indexnowKey) {
    $key = (string) setting('bing_indexnow_key', '');
    abort_unless($key !== '' && hash_equals($key, $indexnowKey), 404);
    return response($key)->header('Content-Type', 'text/plain; charset=utf-8');
})->where('indexnowKey', '[A-Za-z0-9-]{8,128}');
// EPay-compatible gateways call notify_url with GET in some deployments and POST in
// others, so accept both rather than silently 405-ing half of them.
//
// Deliberately NOT throttled. Laravel's throttle is keyed on the caller's IP, and the
// caller here is the gateway — one address for every buyer's callback. BEpusdt sends a
// pending callback per order per minute, so any limit low enough to be worth having
// would 429 real payment notifications on a busy day, and a dropped callback means a
// paid order that never delivers. The flooding these endpoints invite is bounded
// instead where it does no such damage: PaymentController::logContext caps what an
// unauthenticated body can write to the log, config/logging.php rotates it daily with
// 14-day retention, and nginx already applies limit_req 30r/s per IP.
Route::match(['get', 'post'], '/payment/epay/notify', [Front\PaymentController::class, 'epayNotify']);
Route::match(['get', 'post'], '/payment/epusdt/notify', [Front\PaymentController::class, 'epusdtNotify']);

Route::middleware('check.blacklist')->group(function () {
    // Home
    Route::get('/', [Front\HomeController::class, 'index']);

    // Products
    Route::get('/category/{slug}', [Front\ProductController::class, 'category']);
    Route::get('/product/{slug}', [Front\ProductController::class, 'show']);

    // Orders
    // Throttles are an outer bound that runs before the controller, so an attacker
    // cannot make the app spend a bcrypt verification or a stock-locking transaction
    // per request. Creating an order locks cards, so it is the tightest of the three.
    Route::post('/order/create', [Front\OrderController::class, 'create'])
        ->middleware(['turnstile', 'throttle:5,1,front-order-create']);
    // Numeric Laravel throttles otherwise share the same anonymous IP bucket.
    // Polling, price previews and lookup must not consume the checkout allowance.
    Route::post('/order/quote', [Front\CheckoutController::class, 'quote'])->middleware('throttle:30,1,front-order-quote');
    Route::post('/order/cancel/{orderNo}', [Front\OrderController::class, 'cancel'])->middleware('throttle:10,1,front-order-cancel');
    // The pay page polls this route every 5s (12/min) waiting for the callback, so
    // the ceiling has to clear that with room for several buyers behind one NAT.
    // It needs a ceiling at all because order numbers are guessable and each hit on
    // an expired order opens a write transaction — unthrottled, that is a cheap way
    // to load the database from outside.
    Route::get('/order/pay/{order_no}', [Front\OrderController::class, 'pay'])
        ->middleware('throttle:120,1,front-order-pay');
    Route::get('/order/query', [Front\OrderController::class, 'queryForm']);
    Route::post('/order/query', [Front\OrderController::class, 'query'])
        ->middleware(['turnstile', 'throttle:10,1,front-order-query']);
    Route::post('/order/query/page', [Front\OrderController::class, 'queryPage'])->middleware(['turnstile', 'throttle:10,1,front-order-query']);
    Route::post('/order/refund/{orderNo}', [Front\OrderController::class, 'requestRefund'])->middleware('throttle:5,1,front-order-refund');
    Route::get('/order/detail/{order_no}', [Front\OrderController::class, 'detail']);
    // Same session gate as the detail page it is linked from — it serves exactly the
    // content that page already shows, as a file. Throttled anyway: it reads every
    // card row for the order, which is more work than rendering the page.
    Route::get('/order/cards/{order_no}/download', [Front\OrderController::class, 'downloadCards'])
        ->middleware('throttle:30,1,front-card-download');
    Route::post('/order/verify', [Front\OrderController::class, 'verify'])
        ->middleware('throttle:20,1,front-order-verify');

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

Route::prefix('api/' . admin_path())->group(function () {
    Route::post('/login', [ApiAdmin\AuthController::class, 'login']);

    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [ApiAdmin\AuthController::class, 'logout']);
        Route::get('/me', [ApiAdmin\AuthController::class, 'me']);
        Route::post('/password', [ApiAdmin\AuthController::class, 'changePassword']);

        // API access credentials
        Route::get('/api-tokens', [ApiAdmin\ApiTokenController::class, 'index']);
        Route::post('/api-tokens', [ApiAdmin\ApiTokenController::class, 'store']);
        Route::put('/api-tokens/{apiToken}', [ApiAdmin\ApiTokenController::class, 'update']);
        Route::delete('/api-tokens/{apiToken}', [ApiAdmin\ApiTokenController::class, 'destroy']);

        // Dashboard
        Route::get('/dashboard', [ApiAdmin\DashboardController::class, 'index']);
        Route::get('/notifications', [ApiAdmin\NotificationController::class, 'index']);
        Route::post('/notifications/{delivery}/retry', [ApiAdmin\NotificationController::class, 'retry'])->middleware('throttle:20,1,admin-notification-retry');

        // Uploads (image picker + rich text editor)
        Route::post('/upload', [ApiAdmin\UploadController::class, 'store']);

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
        Route::patch('/cards/{card}/status', [ApiAdmin\CardController::class, 'updateStatus']);
        Route::delete('/cards/{card}', [ApiAdmin\CardController::class, 'destroy']);

        // Orders
        Route::get('/orders', [ApiAdmin\OrderController::class, 'index']);
        Route::get('/orders/export', [ApiAdmin\OrderController::class, 'export']);
        Route::get('/orders/{order}', [ApiAdmin\OrderController::class, 'show']);
        Route::post('/orders/{order}/close', [ApiAdmin\OrderController::class, 'close']);
        Route::post('/orders/{order}/paid', [ApiAdmin\OrderController::class, 'markPaid']);
        Route::post('/orders/{order}/resend', [ApiAdmin\OrderController::class, 'resend']);
        Route::post('/orders/{order}/sync', [ApiAdmin\OperationsController::class, 'sync'])->middleware('throttle:10,1,admin-payment-sync');
        Route::post('/orders/{order}/receipts/{receipt}/resolve', [ApiAdmin\OperationsController::class, 'resolve']);
        Route::post('/orders/{order}/refunds', [ApiAdmin\RefundController::class, 'store']);
        Route::get('/refunds', [ApiAdmin\RefundController::class, 'index']);
        Route::put('/refunds/{refund}', [ApiAdmin\RefundController::class, 'update']);
        Route::get('/admins', [ApiAdmin\AdminController::class, 'index']);
        Route::post('/admins', [ApiAdmin\AdminController::class, 'store']);
        Route::put('/admins/{admin}', [ApiAdmin\AdminController::class, 'update']);
        Route::get('/maintenance/health', [ApiAdmin\OperationsController::class, 'health']);
        Route::post('/seo-deliveries/enqueue', [ApiAdmin\OperationsController::class, 'enqueueSeo'])->middleware('throttle:2,1,admin-seo-enqueue');
        Route::get('/maintenance/assets', [ApiAdmin\MaintenanceController::class, 'assets']);
        Route::post('/maintenance/assets', [ApiAdmin\MaintenanceController::class, 'assetAction']);
        Route::get('/maintenance/backups', [ApiAdmin\MaintenanceController::class, 'backups']);
        Route::post('/maintenance/backups', [ApiAdmin\MaintenanceController::class, 'backup'])->middleware('throttle:2,10,admin-shop-backup');
        Route::get('/maintenance/backups/{name}/download', [ApiAdmin\MaintenanceController::class, 'download']);
        Route::post('/maintenance/backups/{name}/validate', [ApiAdmin\MaintenanceController::class, 'validateBackup']);
        Route::get('/seo-deliveries', [ApiAdmin\OperationsController::class, 'seo']);
        Route::post('/seo-deliveries/{delivery}/retry', [ApiAdmin\OperationsController::class, 'retrySeo']);

        // Articles
        Route::get('/articles/product-options', [ApiAdmin\ArticleController::class, 'productOptions']);
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
        Route::put('/blacklists/{blacklist}', [ApiAdmin\BlacklistController::class, 'update']);
        Route::delete('/blacklists/{blacklist}', [ApiAdmin\BlacklistController::class, 'destroy']);

        // Logs
        Route::get('/logs', [ApiAdmin\LogController::class, 'index']);

        // Settings
        Route::get('/settings', [ApiAdmin\SettingController::class, 'index']);
        Route::post('/settings', [ApiAdmin\SettingController::class, 'update']);
        // Throttled: it opens an outbound SMTP connection per call, so it is the one
        // admin action that can be turned into an outbound flood.
        Route::post('/settings/test-email', [ApiAdmin\SettingController::class, 'testEmail'])
            ->middleware('throttle:10,1,admin-test-email');
    });
});

/*
|--------------------------------------------------------------------------
| Admin SPA (React + Ant Design Pro)
|--------------------------------------------------------------------------
| Every GET under the admin path serves the SPA shell; React Router handles the
| rest client-side, and data comes from the /api/<path>/* routes above.
|
| The segment is ADMIN_PATH from .env (default "admin"). Both this route and the
| API prefix move together — hiding only the shell would leave the login endpoint
| sitting at a guessable URL, which is the part that actually gets brute-forced.
|
| When the path is moved, /admin stops being registered at all and returns 404
| rather than redirecting, so the response gives nothing away.
*/

Route::get('/' . admin_path() . '/{any?}', function () {
    return view('admin.spa');
})->where('any', '.*');

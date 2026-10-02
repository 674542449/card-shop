<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Front;
use App\Http\Controllers\Api\Admin as ApiAdmin;

/*
|--------------------------------------------------------------------------
| Frontend Routes
|--------------------------------------------------------------------------
*/

Route::get('/health/ready', [\App\Http\Controllers\ProbeController::class, 'ready'])->name('health.ready');

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
    Route::post('/login/challenge', [ApiAdmin\AuthController::class, 'challenge']);

    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [ApiAdmin\AuthController::class, 'logout'])->defaults('_admin_capability', 'self');
        Route::get('/me', [ApiAdmin\AuthController::class, 'me'])->defaults('_admin_capability', 'self');
        Route::post('/password', [ApiAdmin\AuthController::class, 'changePassword'])->defaults('_admin_capability', 'self');
        Route::post('/two-factor/setup', [ApiAdmin\TwoFactorController::class, 'setup'])->defaults('_admin_capability', 'self');
        Route::post('/two-factor/confirm', [ApiAdmin\TwoFactorController::class, 'confirm'])->defaults('_admin_capability', 'self');
        Route::post('/two-factor/disable', [ApiAdmin\TwoFactorController::class, 'disable'])->defaults('_admin_capability', 'self');

        // API access credentials
        Route::get('/api-tokens', [ApiAdmin\ApiTokenController::class, 'index'])->defaults('_admin_capability', 'tokens:read');
        Route::post('/api-tokens', [ApiAdmin\ApiTokenController::class, 'store'])->defaults('_admin_capability', 'tokens:write');
        Route::put('/api-tokens/{apiToken}', [ApiAdmin\ApiTokenController::class, 'update'])->defaults('_admin_capability', 'tokens:write');
        Route::delete('/api-tokens/{apiToken}', [ApiAdmin\ApiTokenController::class, 'destroy'])->defaults('_admin_capability', 'tokens:write');

        // Dashboard
        Route::get('/dashboard', [ApiAdmin\DashboardController::class, 'index'])->defaults('_admin_capability', 'overview:read');
        Route::get('/notifications', [ApiAdmin\NotificationController::class, 'index'])->defaults('_admin_capability', 'notifications:read');
        Route::post('/notifications/{delivery}/retry', [ApiAdmin\NotificationController::class, 'retry'])->middleware('throttle:20,1,admin-notification-retry')->defaults('_admin_capability', 'notifications:write');

        // Uploads (image picker + rich text editor)
        Route::post('/upload', [ApiAdmin\UploadController::class, 'store'])->defaults('_admin_capability', 'upload');

        // Categories
        Route::get('/categories', [ApiAdmin\CategoryController::class, 'index'])->defaults('_admin_capability', 'catalog:read');
        Route::post('/categories', [ApiAdmin\CategoryController::class, 'store'])->defaults('_admin_capability', 'catalog:write');
        Route::put('/categories/{category}', [ApiAdmin\CategoryController::class, 'update'])->defaults('_admin_capability', 'catalog:write');
        Route::delete('/categories/{category}', [ApiAdmin\CategoryController::class, 'destroy'])->defaults('_admin_capability', 'catalog:write');

        // Products
        Route::get('/products', [ApiAdmin\ProductController::class, 'index'])->defaults('_admin_capability', 'catalog:read');
        Route::post('/products', [ApiAdmin\ProductController::class, 'store'])->defaults('_admin_capability', 'catalog:write');
        Route::get('/products/{product}', [ApiAdmin\ProductController::class, 'show'])->defaults('_admin_capability', 'catalog:read');
        Route::put('/products/{product}', [ApiAdmin\ProductController::class, 'update'])->defaults('_admin_capability', 'catalog:write');
        Route::delete('/products/{product}', [ApiAdmin\ProductController::class, 'destroy'])->defaults('_admin_capability', 'catalog:write');

        // Cards
        Route::get('/products/{product}/cards', [ApiAdmin\CardController::class, 'index'])->defaults('_admin_capability', 'cards:read');
        Route::post('/products/{product}/cards/import', [ApiAdmin\CardController::class, 'import'])->defaults('_admin_capability', 'cards:write');
        Route::delete('/cards/batch-destroy', [ApiAdmin\CardController::class, 'batchDestroy'])->defaults('_admin_capability', 'cards:write');
        Route::patch('/cards/{card}/status', [ApiAdmin\CardController::class, 'updateStatus'])->defaults('_admin_capability', 'cards:write');
        Route::delete('/cards/{card}', [ApiAdmin\CardController::class, 'destroy'])->defaults('_admin_capability', 'cards:write');

        // Orders
        Route::get('/orders', [ApiAdmin\OrderController::class, 'index'])->defaults('_admin_capability', 'orders:read');
        Route::get('/orders/export', [ApiAdmin\OrderController::class, 'export'])->defaults('_admin_capability', 'orders:read');
        Route::get('/orders/{order}', [ApiAdmin\OrderController::class, 'show'])->defaults('_admin_capability', 'orders:read');
        Route::post('/orders/{order}/close', [ApiAdmin\OrderController::class, 'close'])->defaults('_admin_capability', 'orders:write');
        Route::post('/orders/{order}/paid', [ApiAdmin\OrderController::class, 'markPaid'])->defaults('_admin_capability', 'orders.mark_paid');
        Route::post('/orders/{order}/resend', [ApiAdmin\OrderController::class, 'resend'])->defaults('_admin_capability', 'orders:write');
        Route::post('/orders/{order}/replacements', [ApiAdmin\OrderController::class, 'replaceCards'])->defaults('_admin_capability', 'orders.replace_cards');
        Route::post('/orders/{order}/sync', [ApiAdmin\OperationsController::class, 'sync'])->middleware('throttle:10,1,admin-payment-sync')->defaults('_admin_capability', 'orders:write');
        Route::post('/orders/{order}/receipts/{receipt}/resolve', [ApiAdmin\OperationsController::class, 'resolve'])->defaults('_admin_capability', 'orders:write');
        Route::post('/orders/{order}/refunds', [ApiAdmin\RefundController::class, 'store'])->defaults('_admin_capability', 'orders.refund');
        Route::get('/refunds', [ApiAdmin\RefundController::class, 'index'])->defaults('_admin_capability', 'refunds:read');
        Route::put('/refunds/{refund}', [ApiAdmin\RefundController::class, 'update'])->defaults('_admin_capability', 'refunds:write');
        Route::get('/admins', [ApiAdmin\AdminController::class, 'index'])->defaults('_admin_capability', 'accounts');
        Route::post('/admins', [ApiAdmin\AdminController::class, 'store'])->defaults('_admin_capability', 'accounts');
        Route::put('/admins/{admin}', [ApiAdmin\AdminController::class, 'update'])->defaults('_admin_capability', 'accounts');
        Route::get('/maintenance/health', [ApiAdmin\OperationsController::class, 'health'])->defaults('_admin_capability', 'maintenance:read');
        Route::post('/maintenance/health/acknowledge', [ApiAdmin\OperationsController::class, 'acknowledgeHealth'])->defaults('_admin_capability', 'health.acknowledge');
        Route::post('/seo-deliveries/enqueue', [ApiAdmin\OperationsController::class, 'enqueueSeo'])->middleware('throttle:2,1,admin-seo-enqueue')->defaults('_admin_capability', 'content:write');
        Route::get('/maintenance/assets', [ApiAdmin\MaintenanceController::class, 'assets'])->defaults('_admin_capability', 'maintenance:read');
        Route::post('/maintenance/assets', [ApiAdmin\MaintenanceController::class, 'assetAction'])->defaults('_admin_capability', 'maintenance:write');
        Route::get('/maintenance/backups', [ApiAdmin\MaintenanceController::class, 'backups'])->defaults('_admin_capability', 'backups.read');
        Route::post('/maintenance/backups', [ApiAdmin\MaintenanceController::class, 'backup'])->middleware('throttle:2,10,admin-shop-backup')->defaults('_admin_capability', 'backups.write');
        Route::get('/maintenance/backup-runs', [ApiAdmin\MaintenanceController::class, 'backupRuns'])->defaults('_admin_capability', 'backups.read');
        Route::get('/maintenance/backup-runs/{run}', [ApiAdmin\MaintenanceController::class, 'backupRun'])->defaults('_admin_capability', 'backups.read');
        Route::post('/maintenance/backup-runs/{run}/retry', [ApiAdmin\MaintenanceController::class, 'retryBackup'])->defaults('_admin_capability', 'backups.write');
        Route::post('/maintenance/backup-runs/{run}/acknowledge', [ApiAdmin\MaintenanceController::class, 'acknowledgeBackup'])->defaults('_admin_capability', 'backups.write');
        Route::get('/maintenance/backups/{name}/download', [ApiAdmin\MaintenanceController::class, 'download'])->defaults('_admin_capability', 'backups.read');
        Route::post('/maintenance/backups/{name}/validate', [ApiAdmin\MaintenanceController::class, 'validateBackup'])->defaults('_admin_capability', 'backups.write');
        Route::get('/seo-deliveries', [ApiAdmin\OperationsController::class, 'seo'])->defaults('_admin_capability', 'content:read');
        Route::post('/seo-deliveries/{delivery}/retry', [ApiAdmin\OperationsController::class, 'retrySeo'])->defaults('_admin_capability', 'content:write');

        // Articles
        Route::get('/articles/product-options', [ApiAdmin\ArticleController::class, 'productOptions'])->defaults('_admin_capability', 'content:read');
        Route::get('/articles', [ApiAdmin\ArticleController::class, 'index'])->defaults('_admin_capability', 'content:read');
        Route::post('/articles', [ApiAdmin\ArticleController::class, 'store'])->defaults('_admin_capability', 'content:write');
        Route::get('/articles/{article}', [ApiAdmin\ArticleController::class, 'show'])->defaults('_admin_capability', 'content:read');
        Route::put('/articles/{article}', [ApiAdmin\ArticleController::class, 'update'])->defaults('_admin_capability', 'content:write');
        Route::delete('/articles/{article}', [ApiAdmin\ArticleController::class, 'destroy'])->defaults('_admin_capability', 'content:write');

        // Article Categories
        Route::get('/article-categories', [ApiAdmin\ArticleCategoryController::class, 'index'])->defaults('_admin_capability', 'content:read');
        Route::post('/article-categories', [ApiAdmin\ArticleCategoryController::class, 'store'])->defaults('_admin_capability', 'content:write');
        Route::put('/article-categories/{articleCategory}', [ApiAdmin\ArticleCategoryController::class, 'update'])->defaults('_admin_capability', 'content:write');
        Route::delete('/article-categories/{articleCategory}', [ApiAdmin\ArticleCategoryController::class, 'destroy'])->defaults('_admin_capability', 'content:write');

        // Coupons
        Route::get('/coupons/product-options', [ApiAdmin\CouponController::class, 'productOptions'])->defaults('_admin_capability', 'coupons:read');
        Route::get('/coupons', [ApiAdmin\CouponController::class, 'index'])->defaults('_admin_capability', 'coupons:read');
        Route::post('/coupons', [ApiAdmin\CouponController::class, 'store'])->defaults('_admin_capability', 'coupons:write');
        Route::put('/coupons/{coupon}', [ApiAdmin\CouponController::class, 'update'])->defaults('_admin_capability', 'coupons:write');
        Route::delete('/coupons/{coupon}', [ApiAdmin\CouponController::class, 'destroy'])->defaults('_admin_capability', 'coupons:write');

        // Blacklists
        Route::get('/blacklists', [ApiAdmin\BlacklistController::class, 'index'])->defaults('_admin_capability', 'blacklists:read');
        Route::post('/blacklists', [ApiAdmin\BlacklistController::class, 'store'])->defaults('_admin_capability', 'blacklists:write');
        Route::put('/blacklists/{blacklist}', [ApiAdmin\BlacklistController::class, 'update'])->defaults('_admin_capability', 'blacklists:write');
        Route::delete('/blacklists/{blacklist}', [ApiAdmin\BlacklistController::class, 'destroy'])->defaults('_admin_capability', 'blacklists:write');

        // Logs
        Route::get('/logs', [ApiAdmin\LogController::class, 'index'])->defaults('_admin_capability', 'logs:read');

        // Settings
        Route::get('/settings', [ApiAdmin\SettingController::class, 'index'])->defaults('_admin_capability', 'settings:read');
        Route::post('/settings', [ApiAdmin\SettingController::class, 'update'])->defaults('_admin_capability', 'settings:write');
        // Throttled: it opens an outbound SMTP connection per call, so it is the one
        // admin action that can be turned into an outbound flood.
        Route::post('/settings/test-email', [ApiAdmin\SettingController::class, 'testEmail'])
            ->middleware('throttle:10,1,admin-test-email')->defaults('_admin_capability', 'settings.test_email');
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

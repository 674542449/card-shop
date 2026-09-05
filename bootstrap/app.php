<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Runs in the cardshop-scheduler container. Abandoned pending orders hold
        // their cards in the `locked` state; nothing else ever releases them, so
        // without this the sellable stock only ever shrinks.
        // The 10 is the lock's expiry in minutes. withoutOverlapping() defaults to
        // 24 hours, so a run killed mid-flight (container restart, OOM) leaves the
        // lock held and silently stops expiring orders for a full day — stock quietly
        // draining the whole time with nothing in the logs to say why.
        $schedule->command('orders:expire')
            ->everyMinute()
            ->withoutOverlapping(10);
    })
    ->withMiddleware(function (Middleware $middleware) {
        // This should normally be EMPTY. It is not the fix for "visitors all show the
        // CDN's IP" — that is already solved one layer down, in nginx.
        //
        // docker/nginx/default.conf lists Cloudflare's origin ranges via
        // set_real_ip_from and reads CF-Connecting-IP, so nginx rewrites $remote_addr
        // before PHP ever sees the request, and only when the peer really is one of
        // those addresses. REMOTE_ADDR is therefore already the visitor's address and
        // Laravel has no proxy to trust.
        //
        // Setting '*' here is strictly worse than doing nothing: it means "believe
        // whatever IP the client claims", so anyone reaching the origin directly can
        // forge X-Forwarded-For and walk past the rate limits, the per-IP cap of three
        // unpaid orders, and the IP blacklist. The nginx approach has no such hole —
        // a forger cannot make their packets arrive from Cloudflare's ranges.
        //
        // The parsing below stays for the unusual topology where a SECOND reverse proxy
        // sits in front of nginx (self-hosted HAProxy, a non-Cloudflare CDN). List that
        // layer's ranges then. A normal Cloudflare deployment leaves this empty.
        //
        // Read from a real container environment variable rather than .env: this
        // closure runs while the kernel is being resolved, before .env is parsed.
        // docker-compose.yml passes it through.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*'
                    ? '*'
                    : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            );
        }

        $middleware->validateCsrfTokens(except: [
            'payment/epay/notify',
            'payment/epusdt/notify',
        ]);

        // 扫描器蜜罐。放在全局中间件最前面（prepend）：它要在进路由之前就拦下探测，
        // 而扫描器打的大多是没有对应路由的路径（会走到 404）——只有全局中间件才跑得到
        // 那些请求。命中即封禁来源 IP 并返回 404，之后由 CheckBlacklist 挡成 403。
        $middleware->prepend(\App\Http\Middleware\TrapScanners::class);

        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'check.blacklist' => \App\Http\Middleware\CheckBlacklist::class,
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
            'api.token' => \App\Http\Middleware\ApiTokenAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

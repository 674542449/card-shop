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
        // Behind a CDN or reverse proxy the origin sees the proxy's address and a plain
        // HTTP request. Two things break without trusting the forwarded headers: every
        // visitor shares one IP, which collapses the per-IP rate limits and the
        // blacklist into global ones, and Laravel builds http:// asset URLs that an
        // HTTPS page then refuses to load as mixed content.
        //
        // Read from a real container environment variable rather than .env: this
        // closure runs while the kernel is being resolved, before .env is parsed.
        // docker-compose.yml passes it through.
        //
        // SECURITY: '*' trusts X-Forwarded-For from anyone who can reach the origin
        // directly, letting them present any IP they like and walk past the rate
        // limits. Only use '*' when port 80 is firewalled to the CDN; otherwise list
        // the CDN's ranges here instead.
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

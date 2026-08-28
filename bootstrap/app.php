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
        $schedule->command('orders:expire')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
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

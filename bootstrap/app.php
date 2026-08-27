<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'check.blacklist' => \App\Http\Middleware\CheckBlacklist::class,
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

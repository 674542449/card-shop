<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    */

    'driver' => env('SESSION_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    */

    'lifetime' => env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    */

    'encrypt' => env('SESSION_ENCRYPT', false),

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    */

    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    */

    'connection' => env('SESSION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    */

    'table' => env('SESSION_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    */

    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    */

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    */

    'cookie' => env('SESSION_COOKIE', 'cardshop_session'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    */

    'path' => env('SESSION_PATH', '/'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('SESSION_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    */

    // Derived from APP_URL rather than defaulting to null: without the Secure flag,
    // any request that ever reached http:// (a typed URL before HSTS is pinned, a
    // stale link, an <img src="http://…"> on some other page) carries the operator's
    // session in cleartext. Deriving it from the scheme keeps a plain-HTTP install
    // working, where forcing Secure would silently break login instead.
    //
    // The env() default is NOT enough on its own, and that gap shipped: .env.example
    // carried the line `SESSION_SECURE_COOKIE=` — a key that exists with an empty
    // value. env() returns '' for that, not the default, so the derivation below was
    // never evaluated and every HTTPS install created from .env.example sent its
    // session cookie without Secure. Verified directly against Illuminate\Support\Env:
    //     key present but empty  -> ''    (falsy — no Secure flag)
    //     key absent             -> true  (the derivation runs)
    // .env.example no longer writes the empty key, but every .env already copied from
    // it still has one, so an empty value has to be treated as "not set" here. That is
    // what actually repairs existing deployments.
    'secure' => (function () {
        $explicit = env('SESSION_SECURE_COOKIE');

        if ($explicit === null || $explicit === '') {
            return str_starts_with((string) env('APP_URL'), 'https://');
        }

        return filter_var($explicit, FILTER_VALIDATE_BOOL);
    })(),

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    */

    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    */

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    /*
    |--------------------------------------------------------------------------
    | Partitioned Cookies
    |--------------------------------------------------------------------------
    */

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];

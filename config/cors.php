<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | This file exists to NARROW the framework default. Without it Laravel applies
    | its packaged cors config, whose `paths` is ['api/*'] and whose
    | `allowed_origins` is ['*'] — and this app serves the admin API under
    | /api/admin/*, so every admin endpoint was answering with
    | `Access-Control-Allow-Origin: *`.
    |
    | That is not exploitable as it stands: `supports_credentials` is false, so a
    | browser will not attach the admin's session cookie to a cross-origin request
    | and a malicious page gets a 401. The reason to fix it anyway is the blast
    | radius if anyone ever flips that flag to "make CORS work" — at which point
    | any website could read the order list, the card secrets and the settings of
    | a logged-in operator.
    |
    | The admin API is same-origin only: the SPA is served from this very host, so
    | it never needs a CORS header. Only the reseller API under /api/v1/* is meant
    | to be called from elsewhere, and that one authenticates with a bearer token
    | rather than a cookie.
    |
    */

    'paths' => ['api/v1/*'],

    'allowed_methods' => ['GET', 'POST'],

    // '*' is acceptable ONLY while supports_credentials stays false: the reseller
    // API is token-authenticated, so there is no ambient authority for another
    // origin to borrow. Replace with an explicit list if you ever know your
    // integrators' domains.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Must stay false. Turning this on with allowed_origins '*' is rejected by
    // browsers anyway, and turning it on with a real origin list would hand that
    // origin the operator's session.
    'supports_credentials' => false,

];

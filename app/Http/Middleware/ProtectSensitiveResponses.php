<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectSensitiveResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::protect($next($request));
    }

    public static function protect(Response $response): Response
    {
        // Dynamic pages contain CSRF/session credentials; order/API responses may
        // contain the purchased secrets. Apply this to errors and redirects too,
        // without relying on the URL suffix or a controller remembering to do it.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }
}

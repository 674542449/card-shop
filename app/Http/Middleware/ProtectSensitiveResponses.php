<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectSensitiveResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = self::protect($next($request));
        if ($request->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $robots = NoIndex::directiveFor($request);
        if ($robots !== null && !$response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
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
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }
}

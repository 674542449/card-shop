<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\RateLimiter;

class ApiTokenAuth
{
    /**
     * Handle an incoming request.
     *
     * Validates the API token from the Authorization Bearer header.
     * Tokens are stored as SHA-256 hashes in the database for security.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'error' => 'Unauthorized. API token required.',
            ], 401);
        }

        $hashedToken = hash('sha256', $bearerToken);

        $apiToken = ApiToken::where('token', $hashedToken)
            ->where('is_active', true)
            ->first();

        if (!$apiToken || $apiToken->expires_at?->isPast()) {
            return response()->json([
                'error' => 'Invalid or inactive API token.',
            ], 401);
        }

        // Dynamic order/product identifiers belong to the caller. Determine the
        // permission from the matched action, never a substring of their URL.
        $route = $request->route();
        $controller = $route?->getControllerClass();
        $action = $route?->getActionMethod();
        // Laravel registers HEAD alongside GET; both use the same read scope.
        $reading = in_array($request->method(), ['GET', 'HEAD'], true);
        $creatingOrder = $controller === \App\Http\Controllers\Api\OrderController::class
            && $action === 'create' && $request->isMethod('POST');
        $scope = match (true) {
            $controller === \App\Http\Controllers\Api\ProductController::class
                && in_array($action, ['index', 'show'], true) && $reading => 'products:read',
            $creatingOrder => 'orders:create',
            $controller === \App\Http\Controllers\Api\OrderController::class
                && $action === 'cancel' && $request->isMethod('POST') => 'orders:cancel',
            $controller === \App\Http\Controllers\Api\OrderController::class
                && $action === 'show' && $request->isMethod('POST') => 'orders:query',
            $controller === null && $route?->getName() === 'api.orders.legacy-query'
                && $reading => 'orders:query',
            default => null,
        };
        // New actions receive no permission until deliberately mapped above,
        // including legacy all-scope tokens. An unknown route must fail closed.
        if ($scope === null || ($apiToken->scopes !== null && !in_array($scope, $apiToken->scopes, true))
            || ($apiToken->allowed_ips && !\Symfony\Component\HttpFoundation\IpUtils::checkIp($request->ip(), $apiToken->allowed_ips))) {
            return response()->json(['message' => 'API 令牌没有此访问权限或来源 IP 不允许。'], 403)->header('Cache-Control', 'no-store');
        }

        $limits = ['api-token:'.$apiToken->id => $apiToken->requests_per_minute];
        if ($creatingOrder) {
            $limits['api-token-orders:'.$apiToken->id] = $apiToken->orders_per_minute;
        }
        foreach ($limits as $key => $limit) {
            // Gate on the atomic increment rather than a check-then-hit race.
            if (RateLimiter::hit($key, 60) > $limit) {
                return response()->json(['message' => 'API 令牌请求额度已用尽，请稍后重试。'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key))->header('Cache-Control', 'no-store');
            }
        }

        // Update last used timestamp
        $apiToken->update(['last_used_at' => now()]);

        // Make the token record available on the request
        $request->attributes->set('api_token', $apiToken);

        return $this->noStore($next, $request);
    }

    private function noStore(Closure $next, Request $request): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}

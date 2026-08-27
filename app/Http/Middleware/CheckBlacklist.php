<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckBlacklist
{
    /**
     * Handle an incoming request.
     *
     * Check if the client's IP (and email, if present) is blacklisted.
     * Uses Redis cache with a 5-minute TTL for blacklist lookups.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // Check IP against blacklist with cache
        if ($this->isBlocked('ip', $ip)) {
            return $this->denyAccess($request);
        }

        // Check email if present in the request
        $email = $request->input('email');
        if ($email && $this->isBlocked('email', $email)) {
            return $this->denyAccess($request);
        }

        return $next($request);
    }

    /**
     * Check if a value is blocked, using Redis cache for performance.
     */
    private function isBlocked(string $type, string $value): bool
    {
        $cacheKey = "blacklist:{$type}:" . md5($value);

        return Cache::store('redis')->remember($cacheKey, 300, function () use ($type, $value) {
            return \App\Models\Blacklist::where('type', $type)
                ->where('value', $value)
                ->exists();
        });
    }

    /**
     * Return a 403 forbidden response.
     */
    private function denyAccess(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => '访问被拒绝'], 403);
        }

        abort(403, '访问被拒绝');
    }
}

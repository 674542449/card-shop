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

        // Check email if present in the request. The type guard is load-bearing: this
        // middleware runs on every front-end route, so ?email[]=x on ANY of them would
        // otherwise pass an array into a string parameter and 500 the whole site.
        $email = $request->input('email');
        if (is_string($email) && $email !== '' && $this->isBlocked('email', $email)) {
            return $this->denyAccess($request);
        }

        return $next($request);
    }

    /**
     * Check if a value is blocked, using Redis cache for performance.
     */
    private function isBlocked(string $type, string $value): bool
    {
        // Email addresses are case-insensitive in practice, so a blacklist entry
        // typed in lower case would not have matched Buyer@Example.com — the block
        // was one shift key away from being bypassed.
        if ($type === 'email') {
            $value = mb_strtolower($value);
        }

        $cacheKey = "blacklist:{$type}:" . md5($value);

        try {
            return Cache::store('redis')->remember($cacheKey, 300, fn () => $this->lookup($type, $value));
        } catch (\Throwable $cacheError) {
            // An unreachable cache must not take the site down; fall through to the
            // database, and if that is unreachable too, fail open rather than 500.
            try {
                return $this->lookup($type, $value);
            } catch (\Throwable $dbError) {
                return false;
            }
        }
    }

    private function lookup(string $type, string $value): bool
    {
        $query = \App\Models\Blacklist::where('type', $type);

        return $type === 'email'
            ? $query->whereRaw('lower(value) = ?', [$value])->exists()
            : $query->where('value', $value)->exists();
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

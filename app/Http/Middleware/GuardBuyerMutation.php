<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects cross-site non-navigational mutations identified via Fetch Metadata
 * (Sec-Fetch-Site: cross-site) before spending rate-limit quota, bcrypt CPU, or DB locks.
 */
class GuardBuyerMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (setting('cross_site_guard_enabled', '1') === '1' && ! $request->isMethodSafe()) {
            $site = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));
            if ($site === 'cross-site') {
                $response = $request->expectsJson()
                    ? response()->json(['success' => false, 'message' => '拒绝跨站提交请求'], 403)
                    : response('拒绝跨站提交请求', 403);

                return $this->withVary($response);
            }
        }

        return $this->withVary($next($request));
    }

    private function withVary(Response $response): Response
    {
        $existing = (string) $response->headers->get('Vary', '');
        $tokens = array_filter(array_map('trim', explode(',', $existing)));
        if (! in_array('Sec-Fetch-Site', $tokens, true)) {
            $tokens[] = 'Sec-Fetch-Site';
            $response->headers->set('Vary', implode(', ', $tokens));
        }

        return $response;
    }
}

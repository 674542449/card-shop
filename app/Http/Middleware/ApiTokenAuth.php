<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        if (!$apiToken) {
            return response()->json([
                'error' => 'Invalid or inactive API token.',
            ], 401);
        }

        // Update last used timestamp
        $apiToken->update(['last_used_at' => now()]);

        // Make the token record available on the request
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}

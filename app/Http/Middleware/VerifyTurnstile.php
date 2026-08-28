<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Handle an incoming request.
     *
     * Validates the Cloudflare Turnstile challenge response.
     * If turnstile is not configured (secret key is empty), verification is skipped.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secretKey = (string) setting('turnstile_secret_key', '');
        $siteKey = (string) setting('turnstile_site_key', '');

        // Both keys, not just the secret. The layout renders the widget only when the
        // SITE key is set, so a shop with a secret and no site key would show no
        // challenge and then reject every submission for not having solved it —
        // checkout dead with nothing on screen explaining why. Half-configured fails
        // open; that is the right direction for an optional protection.
        if ($secretKey === '' || $siteKey === '') {
            return $next($request);
        }

        $token = $request->input('cf-turnstile-response', '');
        if (!is_string($token)) {
            return $this->fail($request);
        }

        if (empty($token)) {
            return $this->fail($request);
        }

        try {
            $response = Http::timeout(10)->asForm()->post(self::VERIFY_URL, [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            if (!$response->successful() || !($response->json('success') === true)) {
                Log::info('Turnstile verification failed', [
                    'ip' => $request->ip(),
                    'response' => $response->json(),
                ]);
                return $this->fail($request);
            }
        } catch (\Throwable $e) {
            Log::error('Turnstile verification error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            return $this->fail($request);
        }

        return $next($request);
    }

    /**
     * Return a failure response for failed Turnstile verification.
     */
    private function fail(Request $request): Response
    {
        $message = '人机验证失败，请重试';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['turnstile' => $message])->withInput();
    }
}

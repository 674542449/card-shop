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

        // Skip verification if Turnstile is not configured
        if (empty($secretKey)) {
            return $next($request);
        }

        $token = $request->input('cf-turnstile-response', '');

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

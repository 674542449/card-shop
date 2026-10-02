<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Add stable error codes to JSON APIs without changing HTML or gateway replies. */
final class ApiErrorContract
{
    public static function apply(Response $response): Response
    {
        if (app()->bound('request')) {
            $request = request();
            $id = $request->attributes->get('request_id');
            if (is_string($id)) { $response->headers->set('X-Request-ID', $id); }
            // JsonResponse::setData regenerates content; HEAD must remain empty.
            if ($request->isMethod('HEAD')) { return $response; }
        }
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) { return $response; }
        $payload = $response->getData(true);
        if (! is_array($payload)) { return $response; }
        $codes = [400 => 'invalid_request', 401 => 'authentication_required', 403 => 'permission_denied',
            404 => 'not_found', 405 => 'method_not_allowed', 409 => 'state_conflict',
            410 => 'resource_gone', 419 => 'csrf_token_expired', 422 => 'validation_failed',
            429 => 'rate_limited', 503 => 'service_unavailable'];
        $payload['code'] ??= $codes[$response->getStatusCode()] ?? ($response->getStatusCode() >= 500 ? 'internal_error' : 'request_failed');
        $response->setData($payload);
        return $response;
    }
}

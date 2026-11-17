<?php

namespace App\Http\Middleware;

use App\Exceptions\MaintenanceWriteBlockedException;
use App\Services\MaintenanceWriteBarrier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RequestCorrelation
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->header('X-Request-ID', '');
        $id = is_string($provided) && preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', $provided)
            ? $provided : (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        $logger = Log::getFacadeRoot();
        $supportsContext = $logger instanceof \Illuminate\Log\LogManager
            || (is_object($logger) && method_exists($logger, 'withContext'));
        if ($supportsContext) { $logger->withContext(['request_id' => $id]); }
        try {
            $response = app(MaintenanceWriteBarrier::class)->run(fn () => $next($request));
        } catch (MaintenanceWriteBlockedException) {
            $response = $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => '商城正在维护，请稍后重试。', 'code' => 'maintenance'], 503)
                : response('商城正在维护，请稍后重试。', 503);
            $response->headers->set('Retry-After', '60');
        } finally {
            if ($supportsContext && method_exists($logger, 'withoutContext')) { $logger->withoutContext(); }
        }
        $response->headers->set('X-Request-ID', $id);
        return $response;
    }
}

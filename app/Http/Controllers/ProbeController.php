<?php

namespace App\Http\Controllers;

use App\Services\ReadinessService;
use Illuminate\Http\Request;

class ProbeController extends Controller
{
    public function ready(Request $request, ReadinessService $readiness)
    {
        $address = (string) $request->server('REMOTE_ADDR', '');
        $token = (string) config('probes.token', '');
        $provided = $request->header('X-Shop-Probe-Token', '');
        $authorized = in_array($address, ['127.0.0.1', '::1'], true)
            || ($token !== '' && is_string($provided) && hash_equals($token, $provided));
        abort_unless($authorized, 404);
        $result = $readiness->check();
        return response()->json(['ready' => $result['ready']], $result['ready'] ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}

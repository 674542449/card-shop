<?php

namespace App\Http\Middleware;

use App\Support\ApiErrorContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiErrorContractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return ApiErrorContract::apply($next($request));
    }
}

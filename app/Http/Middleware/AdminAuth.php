<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '未登录'], 401);
            }
            return redirect('/admin/login');
        }

        $admin = Admin::find($adminId);

        if (!$admin) {
            $request->session()->forget('admin_id');
            if ($request->expectsJson()) {
                return response()->json(['message' => '未登录'], 401);
            }
            return redirect('/admin/login');
        }

        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}

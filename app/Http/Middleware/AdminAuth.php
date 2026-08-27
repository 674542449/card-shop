<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * Verify that the admin is authenticated via the session.
     * If not, redirect to the admin login page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            return redirect('/admin/login');
        }

        $admin = Admin::find($adminId);

        if (!$admin) {
            $request->session()->forget('admin_id');
            return redirect('/admin/login');
        }

        // Make the current admin available throughout the request
        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Models\Admin;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (session()->has('admin_id')) {
            return redirect('/admin');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $key = 'admin-login|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['error' => '登录尝试过多，请稍后再试。']);
        }

        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->input('username'))->first();

        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['error' => '用户名或密码错误。'])->withInput();
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        OperationLog::log('登录', 'admin', $admin->id, '管理员登录');

        return redirect('/admin');
    }

    public function logout(Request $request)
    {
        OperationLog::log('登出', null, null, '管理员登出');

        $request->session()->flush();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}

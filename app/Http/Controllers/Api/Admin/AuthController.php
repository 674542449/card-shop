<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $key = 'admin-login|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => '登录尝试过多，请稍后再试。'], 429);
        }

        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->input('username'))->first();

        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            RateLimiter::hit($key, 60);
            return response()->json(['message' => '用户名或密码错误。'], 422);
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

        return response()->json([
            'id' => $admin->id,
            'username' => $admin->username,
        ]);
    }

    public function logout(Request $request)
    {
        OperationLog::log('登出', null, null, '管理员登出');

        $request->session()->flush();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ok']);
    }

    public function me(Request $request)
    {
        $admin = $request->attributes->get('admin');
        return response()->json([
            'id' => $admin->id,
            'username' => $admin->username,
            'last_login_at' => $admin->last_login_at,
            'last_login_ip' => $admin->last_login_ip,
        ]);
    }
}

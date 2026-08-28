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
            // session()->regenerate() above rotated the CSRF token. The SPA was rendered
            // with the pre-login token in its meta tag, so it must adopt this one or every
            // subsequent write is rejected with 419.
            'csrf_token' => csrf_token(),
        ]);
    }

    public function logout(Request $request)
    {
        OperationLog::log('登出', null, null, '管理员登出');

        $request->session()->flush();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'ok',
            'csrf_token' => csrf_token(),
        ]);
    }

    /**
     * Change the signed-in administrator's own password.
     *
     * Until this existed there was no way to move off the seeded password from inside
     * the product at all.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12', 'confirmed'],
        ], [
            'current_password.required' => '请输入当前密码。',
            'new_password.required' => '请输入新密码。',
            'new_password.min' => '新密码至少需要 12 个字符。',
            'new_password.confirmed' => '两次输入的新密码不一致。',
        ]);

        /** @var \App\Models\Admin $admin */
        $admin = $request->attributes->get('admin');

        if (!Hash::check($request->input('current_password'), $admin->password)) {
            return response()->json(['message' => '当前密码不正确。'], 422);
        }

        $admin->update(['password' => Hash::make($request->input('new_password'))]);

        // Rotate the session so any other session holding the old credentials is not
        // silently left signed in, and hand the SPA the token that rotation produced.
        $request->session()->regenerate();
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);

        OperationLog::log('修改密码', 'admin', $admin->id, '管理员修改了自己的密码');

        return response()->json([
            'message' => '密码已更新。',
            'csrf_token' => csrf_token(),
        ]);
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

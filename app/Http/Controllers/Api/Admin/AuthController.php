<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminAuth;
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
        // 见 AdminAuth：会话绑到当时的密码哈希上，密码一变所有旧会话立即失效。
        $request->session()->put('admin_pw', AdminAuth::passwordFingerprint($admin->password));

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
        $admin->refresh();

        // 这里原来的注释写着 regenerate() 能让「其他持有旧凭据的会话不会被悄悄留在
        // 登录状态」——那是错的，而且错得很危险：regenerate() 默认 $destroy=false，
        // 只换当前请求自己的 session ID，存储里同一个管理员的其他会话完全不受影响。
        // 也就是说「察觉被入侵 → 改密码」这个标准补救动作，在此之前对被盗的 cookie
        // 一点作用都没有，而且那个 cookie 会被每次请求续期，攻击者只要保持活动就永不
        // 掉线。真正让旧会话失效的是 AdminAuth 里的密码指纹比对；这里换 session ID
        // 只是防会话固定，并顺带给 SPA 一枚新的 CSRF token。
        $request->session()->regenerate();
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);
        // 自己这条会话跟着新密码走，否则改完密码当场把自己也踢下线。
        $request->session()->put('admin_pw', AdminAuth::passwordFingerprint($admin->password));

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

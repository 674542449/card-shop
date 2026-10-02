<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminAuth;
use App\Models\Admin;
use App\Models\OperationLog;
use App\Rules\BcryptPassword;
use App\Rules\Utf8Text;
use App\Services\AdminTwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => ['bail', 'required', 'string', 'max:50', new Utf8Text],
            'password' => ['bail', 'required', 'string', 'max:72', new BcryptPassword],
        ]);

        $keys = [
            // The account budget is shared across IPs. IP-only throttling allows a
            // proxy pool to try the same owner's password without any upper bound.
            'admin-login-account|'.hash('sha256', mb_strtolower($request->input('username'))) => [10, 900],
            'admin-login|'.$request->ip() => [5, 60],
        ];
        if (($retry = $this->reserveCredentialAttempt($keys)) !== null) {
            return response()->json(['message' => '登录尝试过多，请稍后再试。'], 429)
                ->header('Retry-After', (string) $retry);
        }

        $admin = Admin::where('username', $request->input('username'))->first();

        if (!$admin || !$admin->is_active || !Hash::check($request->input('password'), $admin->password)) {
            return response()->json(['message' => '用户名或密码错误。'], 422);
        }

        foreach (array_keys($keys) as $key) { RateLimiter::clear($key); }

        if ($admin->two_factor_confirmed_at) {
            $request->session()->regenerate(true);
            $request->session()->forget(['admin_id', 'admin_username', 'admin_pw', 'admin_factor']);
            $request->session()->put('admin_challenge', ['id' => $admin->id, 'password' => AdminAuth::passwordFingerprint($admin->password),
                'revision' => $admin->two_factor_revision, 'expires' => now()->addMinutes(5)->timestamp]);
            return response()->json(['two_factor_required' => true, 'csrf_token' => csrf_token()])->header('Cache-Control', 'no-store');
        }
        return $this->completeLogin($request, $admin);
    }

    public function challenge(Request $request, AdminTwoFactorService $factors)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32', new Utf8Text]]);
        $challenge = $request->session()->get('admin_challenge');
        if (!is_array($challenge) || ($challenge['expires'] ?? 0) < now()->timestamp) {
            $request->session()->forget('admin_challenge');
            return response()->json(['message' => '验证已过期，请重新输入账号密码。'], 401);
        }
        $keys = ['admin-factor-account|'.$challenge['id'] => [10, 900], 'admin-factor-ip|'.$request->ip() => [30, 900]];
        if (($retry = $this->reserveCredentialAttempt($keys)) !== null) {
            return response()->json(['message' => '验证码尝试过多，请稍后再试。'], 429)->header('Retry-After', (string) $retry);
        }
        $admin = Admin::find($challenge['id']);
        if (!$admin || !$admin->is_active || !$admin->two_factor_confirmed_at ||
            !hash_equals(AdminAuth::passwordFingerprint($admin->password), (string) ($challenge['password'] ?? '')) ||
            !hash_equals((string) $admin->two_factor_revision, (string) ($challenge['revision'] ?? '')) || !$factors->consume($admin, $data['code'])) {
            return response()->json(['message' => '验证码或恢复码无效，或已经使用。'], 422);
        }
        foreach (array_keys($keys) as $key) { RateLimiter::clear($key); }
        $request->session()->forget('admin_challenge');
        return $this->completeLogin($request, $admin);
    }

    private function completeLogin(Request $request, Admin $admin)
    {
        $request->session()->forget(['admin_challenge', 'admin_factor_setup']);
        $request->session()->regenerate(true);
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);
        // 见 AdminAuth：会话绑到当时的密码哈希上，密码一变所有旧会话立即失效。
        $request->session()->put('admin_pw', AdminAuth::passwordFingerprint($admin->password));
        $request->session()->put('admin_factor', $admin->two_factor_revision);

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

        $request->session()->invalidate();
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
            'current_password' => ['bail', 'required', 'string', 'max:72', new BcryptPassword],
            'new_password' => ['bail', 'required', 'string', 'min:12', 'max:72', 'confirmed', new BcryptPassword],
        ], [
            'current_password.required' => '请输入当前密码。',
            'new_password.required' => '请输入新密码。',
            'new_password.min' => '新密码至少需要 12 个字符。',
            'new_password.confirmed' => '两次输入的新密码不一致。',
        ]);

        /** @var \App\Models\Admin $admin */
        $admin = $request->attributes->get('admin');

        $keys = [
            'admin-password-account|'.$admin->id => [5, 900],
            'admin-password-ip|'.$request->ip() => [20, 900],
        ];
        if (($retry = $this->reserveCredentialAttempt($keys)) !== null) {
            return response()->json(['message' => '当前密码验证尝试过多，请稍后再试。'], 429)
                ->header('Retry-After', (string) $retry);
        }

        if (!Hash::check($request->input('current_password'), $admin->password)) {
            return response()->json(['message' => '当前密码不正确。'], 422);
        }
        foreach (array_keys($keys) as $key) { RateLimiter::clear($key); }

        $updated = DB::transaction(function () use ($admin, $request) {
            $locked = Admin::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->is_active && hash_equals($admin->password, $locked->password) &&
                hash_equals((string) $admin->two_factor_revision, (string) $locked->two_factor_revision), 409, '账户安全状态已变化，请重新登录。');
            $locked->update(['password' => Hash::make($request->input('new_password'))]);
            return $locked;
        });
        // Keep the exact state this operation wrote. A later reset must invalidate it,
        // rather than being silently adopted by a post-commit refresh().
        $admin->setRawAttributes($updated->getAttributes(), true);

        // 这里原来的注释写着 regenerate() 能让「其他持有旧凭据的会话不会被悄悄留在
        // 登录状态」——那是错的，而且错得很危险：regenerate() 默认 $destroy=false，
        // 只换当前请求自己的 session ID，存储里同一个管理员的其他会话完全不受影响。
        // 也就是说「察觉被入侵 → 改密码」这个标准补救动作，在此之前对被盗的 cookie
        // 一点作用都没有，而且那个 cookie 会被每次请求续期，攻击者只要保持活动就永不
        // 掉线。真正让旧会话失效的是 AdminAuth 里的密码指纹比对；这里换 session ID
        // 只是防会话固定，并顺带给 SPA 一枚新的 CSRF token。
        $request->session()->regenerate(true);
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_username', $admin->username);
        // 自己这条会话跟着新密码走，否则改完密码当场把自己也踢下线。
        $request->session()->put('admin_pw', AdminAuth::passwordFingerprint($admin->password));
        $request->session()->put('admin_factor', $admin->two_factor_revision);

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
            'role' => $admin->role, 'permissions' => $admin->permissions,
            'two_factor_enabled' => (bool) $admin->two_factor_confirmed_at,
            'recovery_codes_remaining' => count($admin->two_factor_recovery_codes ?? []),
            'permission_definition' => \App\Policies\AdminPolicy::definition($admin),
        ]);
    }

    /** Reserve before checking bcrypt so parallel guesses cannot race the limit. */
    private function reserveCredentialAttempt(array $limits): ?int
    {
        foreach ($limits as $key => [$maximum, $seconds]) {
            if (RateLimiter::hit($key, $seconds) > $maximum) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }
}

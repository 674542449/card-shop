<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, OperationLog};
use App\Rules\BcryptPassword;
use App\Services\AdminTwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter};

class TwoFactorController extends Controller
{
    private function verifyPassword(Request $request): Admin
    {
        $request->validate(['current_password' => ['bail', 'required', 'string', 'max:72', new BcryptPassword]]);
        $admin = $request->attributes->get('admin');
        $key = 'admin-factor-settings|'.$admin->id;
        abort_if(RateLimiter::hit($key, 900) > 10, 429, '验证尝试过多，请稍后再试。');
        abort_unless(Hash::check($request->input('current_password'), $admin->password), 422, '当前密码不正确。');
        return $admin;
    }

    public function setup(Request $request, AdminTwoFactorService $factors)
    {
        $admin = $this->verifyPassword($request);
        abort_if($admin->two_factor_confirmed_at, 422, '双重验证已经启用。');
        $secret = $factors->generateSecret();
        $request->session()->put('admin_factor_setup', ['id' => $admin->id, 'secret' => encrypt($secret),
            'password' => AdminAuth::passwordFingerprint($admin->password), 'revision' => $admin->two_factor_revision, 'expires' => now()->addMinutes(5)->timestamp]);
        $label = rawurlencode('CardShop:'.$admin->username);
        return response()->json(['secret' => $secret, 'uri' => 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer=CardShop&algorithm=SHA1&digits=6&period=30'])
            ->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, AdminTwoFactorService $factors)
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $admin = $request->attributes->get('admin');
        abort_if(RateLimiter::hit('admin-factor-confirm|'.$admin->id, 900) > 10, 429, '验证尝试过多，请稍后再试。');
        $setup = $request->session()->get('admin_factor_setup');
        abort_unless(is_array($setup) && ($setup['expires'] ?? 0) >= now()->timestamp && ($setup['id'] ?? null) === $admin->id &&
            hash_equals(AdminAuth::passwordFingerprint($admin->password), (string) ($setup['password'] ?? '')) &&
            hash_equals((string) $admin->two_factor_revision, (string) ($setup['revision'] ?? '')), 422, '绑定已过期，请重新开始。');
        $secret = decrypt($setup['secret']);
        $counter = $factors->matchingCounter($secret, $data['code']);
        abort_if($counter === null, 422, '验证码不正确，请确认手机时间后重试。');
        $codes = $factors->recoveryCodes();
        $updated = DB::transaction(function () use ($admin, $secret, $counter, $codes, $factors, $setup) {
            $locked = Admin::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            abort_if(!$locked->is_active || $locked->two_factor_confirmed_at || !hash_equals(AdminAuth::passwordFingerprint($locked->password), $setup['password']) ||
                !hash_equals((string) $locked->two_factor_revision, (string) $admin->two_factor_revision), 409, '账户已变化，请重新加载。');
            $locked->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => array_map($factors->recoveryHash(...), $codes),
                'two_factor_revision' => bin2hex(random_bytes(16)), 'two_factor_confirmed_at' => now(), 'two_factor_last_counter' => $counter])->save();
            return $locked;
        });
        $request->session()->forget('admin_factor_setup');
        return $this->changed($request, $updated, '启用双重验证', ['recovery_codes' => $codes]);
    }

    public function disable(Request $request, AdminTwoFactorService $factors)
    {
        $admin = $this->verifyPassword($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $updated = null;
        abort_unless($factors->consume($admin, $data['code'], function (Admin $locked) use (&$updated) {
            $locked->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_revision' => bin2hex(random_bytes(16)),
                'two_factor_confirmed_at' => null, 'two_factor_last_counter' => null])->save();
            $updated = $locked;
        }), 422, '验证码或恢复码无效，或已经使用。');
        return $this->changed($request, $updated, '停用双重验证');
    }

    private function changed(Request $request, Admin $admin, string $action, array $extra = [])
    {
        $request->attributes->get('admin')->setRawAttributes($admin->getAttributes(), true);
        $request->session()->regenerate(true);
        $request->session()->put('admin_factor', $admin->two_factor_revision);
        OperationLog::log($action, 'admin', $admin->id, $action);
        return response()->json($extra + ['message' => $action.'成功。', 'csrf_token' => csrf_token(), 'admin_context' => AdminAuth::contextFingerprint($admin)])
            ->header('Cache-Control', 'no-store');
    }
}

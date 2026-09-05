<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\OperationLog;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Settings whose value is a credential.
     *
     * index() replaces these with MASK instead of the real value, and update() skips
     * any field that comes back still masked. The settings screen used to hand every
     * one of these to the browser in plaintext on load — the EPay merchant key that
     * signs payment requests, the USDT API token, the Turnstile secret — so anything
     * that could read the admin page could read them all. The operator can still tell
     * a configured secret from an empty one, which is the only thing they need to see.
     */
    private const SECRET_KEYS = [
        'epay_merchant_key',
        'epusdt_api_token',
        'turnstile_secret_key',
        'telegram_bot_token',
        'mail_password',
    ];

    /** Sent in place of a stored secret, and refused as an incoming value. */
    private const MASK = '********';

    public function index()
    {
        $settings = [];
        foreach (Setting::all() as $setting) {
            $settings[$setting->key] = in_array($setting->key, self::SECRET_KEYS, true)
                && (string) $setting->value !== ''
                ? self::MASK
                : $setting->value;
        }

        // 前台模板是「磁盘上有什么」决定的，不是设置项能穷举的。把可选值一起带回去，
        // 后台就不用再发一次请求，也不会出现下拉框里列着一个已经被删掉的模板。
        // 下划线开头表示这不是设置项：update() 的白名单里没有它，写不进数据库。
        $settings['_available_themes'] = themes_available();

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $settingGroups = [
            'site' => [
                'site_name', 'site_theme', 'site_description', 'site_logo', 'site_favicon',
                'site_announcement', 'popup_announcement', 'popup_interval_hours',
                'contact_text', 'contact_url', 'contact_qr_image',
                'footer_powered_by',
            ],
            'payment' => [
                'epay_api_url', 'epay_merchant_id', 'epay_merchant_key',
                'epusdt_api_url', 'epusdt_api_token', 'usdt_gateway',
            ],
            'email' => [
                'email_template_subject', 'email_template_body',
                // SMTP moved out of .env: changing where card secrets are sent from
                // used to need SSH, a file edit and a container restart.
                'mail_host', 'mail_port', 'mail_username', 'mail_password',
                'mail_encryption', 'mail_from_address', 'mail_from_name',
            ],
            'telegram' => [
                'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled',
            ],
            'seo' => [
                'seo_default_title', 'seo_default_description', 'seo_default_keywords',
                'baidu_push_token', 'bing_indexnow_key',
            ],
            'security' => [
                'turnstile_site_key', 'turnstile_secret_key', 'order_expire_minutes',
                // 扫描器蜜罐（TrapScanners 中间件读取）。都有代码级默认值，不配也能跑。
                'honeypot_enabled', 'honeypot_ban_minutes',
                'honeypot_whitelist', 'honeypot_skip_reserved_ips',
            ],
        ];

        foreach ($settingGroups as $group => $keys) {
            foreach ($keys as $key) {
                if (!$request->has($key)) {
                    continue;
                }

                $value = $request->input($key);

                // The form posts back whatever index() sent it, so a secret the
                // operator did not touch arrives as the mask. Writing that would
                // replace a working credential with eight asterisks — and the failure
                // would surface later as payments silently breaking.
                if (in_array($key, self::SECRET_KEYS, true) && $value === self::MASK) {
                    continue;
                }

                // The settings column is text and the readers compare against '0'/'1'.
                // A switch in the admin posts a real boolean, and PHP casts false to
                // the empty string — which reads back as "not set" rather than "off",
                // a distinction that matters for a default of '1'.
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                // 支付时限是买家付款窗口，直接决定订单存活多久。消费方读它是
                // (int) setting('order_expire_minutes', 30)：存进 0、负数或非数字时
                // (int) 会得到 0 或负值，now()->addMinutes() 得到一个「已经过去」的
                // 到期时间，于是每一笔订单一建好就是过期状态、锁定的卡立刻被释放，
                // 而迟到的网关回调仍可能发货——整条下单链路被一个手滑的设置搞瘫。
                // 后台是可信的，但可信不等于不会填错，所以在写库前夹到合理区间：
                // 最短 5 分钟（够走完一次支付），最长 7 天。非数字回落到默认 30。
                if ($key === 'order_expire_minutes') {
                    $minutes = is_numeric($value) ? (int) $value : 30;
                    $value = (string) max(5, min(10080, $minutes));
                }

                Setting::set($key, $value, $group);
            }
        }

        OperationLog::log('更新设置', 'setting', null, '更新系统设置');

        return response()->json(['message' => '设置已保存。']);
    }

    /**
     * Send a test email to prove the SMTP settings actually work.
     *
     * Every other path swallows mail failures on purpose so a dead mail server cannot
     * break a sale, which leaves this as the only way for an operator to find out
     * their settings are wrong before a buyer does.
     */
    public function testEmail(Request $request, NotificationService $notifications)
    {
        $data = $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => '请填写接收测试邮件的地址', 'email.email' => '邮箱格式不正确']
        );

        $result = $notifications->sendTestEmail($data['email']);

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 422);
    }
}

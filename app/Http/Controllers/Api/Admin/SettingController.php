<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\OperationLog;
use App\Services\NotificationService;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        'baidu_push_token',
    ];

    /** Sent in place of a stored secret, and refused as an incoming value. */
    private const MASK = '********';

    /** Delivery destinations, payment trust, and anti-abuse controls require the owner. */
    private const OWNER_ONLY_KEYS = [
        'epay_api_url', 'epay_merchant_id', 'epay_merchant_key',
        'epusdt_api_url', 'epusdt_api_token', 'usdt_gateway', 'payment_reconciliation_enabled',
        'email_template_subject', 'email_template_body',
        'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled',
        'turnstile_site_key', 'turnstile_secret_key', 'order_expire_minutes',
        'honeypot_enabled', 'honeypot_ban_minutes', 'honeypot_whitelist', 'honeypot_skip_reserved_ips',
    ];

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
                'site_url',
            ],
            'payment' => [
                'epay_api_url', 'epay_merchant_id', 'epay_merchant_key',
                'epusdt_api_url', 'epusdt_api_token', 'usdt_gateway',
                'payment_reconciliation_enabled',
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

        $rules = [];
        foreach ($settingGroups as $keys) {
            foreach ($keys as $key) {
                $rules[$key] = ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                    if (!is_scalar($value)) {
                        $fail('设置值必须是文本、数字或开关值。');
                    }
                }];
            }
        }
        $rules['mail_port'] = ['nullable', 'integer', 'min:1', 'max:65535'];
        foreach (['site_url', 'epay_api_url', 'epusdt_api_url'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:'.($key === 'site_url' ? 255 : 2048), function (string $attribute, mixed $value, \Closure $fail) {
                if (SafeUrl::http($value) === null) {
                    $fail('地址必须是有效的 HTTP 或 HTTPS URL，不能包含登录凭据。');
                }
            }];
        }
        foreach (['site_logo', 'site_favicon', 'contact_qr_image'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (SafeUrl::asset($value) === null) {
                    $fail('图片地址必须是站内路径或 HTTP/HTTPS URL。');
                }
            }];
        }
        $rules['contact_url'] = ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
            if (SafeUrl::contact($value) === null) {
                $fail('联系链接仅支持站内路径、HTTP/HTTPS、mailto 或 tel。');
            }
        }];
        foreach (['site_announcement', 'popup_announcement', 'email_template_body'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:1000000'];
        }
        $rules['bing_indexnow_key'] = ['nullable', 'regex:/^[A-Za-z0-9-]{8,128}$/D'];
        $rules['payment_reconciliation_enabled'] = ['nullable', 'boolean'];
        $rules['mail_encryption'] = ['nullable', 'in:ssl,tls,none'];
        $rules['usdt_gateway'] = ['nullable', 'in:epusdt,bepusdt'];
        $rules['site_theme'] = ['nullable', \Illuminate\Validation\Rule::in(themes_available())];
        $rules['order_expire_minutes'] = ['nullable', 'integer', 'min:5', 'max:10080'];
        $rules['popup_interval_hours'] = ['nullable', 'integer', 'min:0', 'max:8760'];
        $rules['honeypot_ban_minutes'] = ['nullable', 'integer', 'min:0', 'max:525600'];
        foreach (['telegram_enabled', 'honeypot_enabled', 'honeypot_skip_reserved_ips'] as $key) {
            $rules[$key] = ['nullable', 'boolean'];
        }
        $rules['honeypot_whitelist'] = ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
            if (!is_string($value)) {
                return;
            }
            foreach (array_filter(array_map('trim', explode(',', $value))) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $fail('白名单必须是用逗号分隔的有效 IP 地址。');
                    return;
                }
            }
        }];
        $request->validate($rules);

        $isOwner = $request->attributes->get('admin')->role === 'owner';
        if (!$isOwner) {
            $stored = Setting::whereIn('key', self::OWNER_ONLY_KEYS)->pluck('value', 'key');
            foreach (self::OWNER_ONLY_KEYS as $key) {
                if (!$request->has($key)) {
                    continue;
                }
                $value = $request->input($key);
                if (in_array($key, self::SECRET_KEYS, true) && $value === self::MASK) {
                    continue;
                }
                $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                if (in_array($key, self::SECRET_KEYS, true)) {
                    // Never compare an untrusted cleartext guess with a saved
                    // credential and expose whether it matched via 200/403. The
                    // settings UI only echoes the mask, or an already-empty value.
                    abort_unless($value === '' && (string) ($stored[$key] ?? '') === '', 403,
                        '仅店主管理员可以修改支付、发货邮件、Telegram 与安全防护配置。');
                    continue;
                }
                // Full-form submissions may echo values already displayed in the
                // UI. Permit identical values, but reject the whole write before
                // saving any ordinary setting when one protected value changed.
                abort_unless(hash_equals((string) ($stored[$key] ?? ''), $value), 403,
                    '仅店主管理员可以修改支付、发货邮件、Telegram 与安全防护配置。');
            }
        }

        DB::transaction(function () use ($settingGroups, $request, $isOwner) {
            foreach ($settingGroups as $group => $keys) {
                foreach ($keys as $key) {
                    // An accepted unchanged echo is still not authority to write.
                    // Skip protected keys so it cannot roll back an owner's
                    // concurrent credential update or alter null/default semantics.
                    if (!$isOwner && in_array($key, self::OWNER_ONLY_KEYS, true)) {
                        continue;
                    }
                    if (!$request->has($key)) {
                        continue;
                    }

                    $value = $request->input($key);

                    // Posting the displayed mask must preserve the saved credential.
                    if (in_array($key, self::SECRET_KEYS, true) && $value === self::MASK) {
                        continue;
                    }

                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    if ($key === 'order_expire_minutes' && $value === null) {
                        $value = '30';
                    }
                    if ($key === 'honeypot_ban_minutes' && $value === null) {
                        $value = '10080';
                    }
                    if ($key === 'popup_interval_hours' && $value === null) {
                        $value = '24';
                    }

                    Setting::set($key, $value, $group);
                }
            }
        });
        settings_forget();

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
        abort_unless($request->attributes->get('admin')->role === 'owner', 403, '仅店主管理员可以测试发货邮箱。');
        $data = $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => '请填写接收测试邮件的地址', 'email.email' => '邮箱格式不正确']
        );

        $result = $notifications->sendTestEmail($data['email']);

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 422);
    }
}

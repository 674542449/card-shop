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

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $settingGroups = [
            'site' => [
                'site_name', 'site_description', 'site_logo', 'site_favicon',
                'site_announcement', 'popup_announcement', 'popup_interval_hours',
                'contact_text', 'contact_url', 'contact_qr_image',
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

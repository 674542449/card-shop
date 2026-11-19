<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\OperationLog;
use App\Services\NotificationService;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\Admin\SettingsResource;

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
    private const SECRET_KEYS = \App\Security\SecretSettings::KEYS;

    /** Sent in place of a stored secret, and refused as an incoming value. */
    private const MASK = '********';


    public function index()
    {
        return response()->json((new SettingsResource(Setting::all()))->resolve(request()));
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
                'telegram_broadcast_bot_token', 'telegram_notify_product_sold', 'telegram_notify_product_listed',
                'telegram_target_bot_enabled', 'telegram_target_bot_chat_ids',
                'telegram_target_group_enabled', 'telegram_target_group_ids',
                'telegram_target_channel_enabled', 'telegram_target_channel_ids',
            ],
            'seo' => [
                'seo_default_title', 'seo_default_description', 'seo_default_keywords',
                'baidu_push_token', 'bing_indexnow_key',
            ],
            'security' => [
                'turnstile_site_key', 'turnstile_secret_key', 'order_expire_minutes',
                'checkout_require_key', 'order_max_pending_per_ip', 'order_max_pending_per_email', 'order_max_locked_percent',
                // 扫描器蜜罐（TrapScanners 中间件读取）。都有代码级默认值，不配也能跑。
                'honeypot_enabled', 'honeypot_ban_minutes',
                'honeypot_whitelist', 'honeypot_skip_reserved_ips',
            ],
            'after_sales' => ['refund_enabled'],
            'backup' => ['backup_auto_enabled', 'backup_schedule_time', 'backup_retention_count', 'backup_retention_days',
                'backup_sync_directory', 'backup_stale_hours', 'backup_min_free_mb'],
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
        $rules['site_url'] = ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
            if (SafeUrl::http($value) === null) {
                $fail('地址必须是有效的 HTTP 或 HTTPS URL，不能包含登录凭据。');
            }
        }];
        foreach (['epay_api_url', 'epusdt_api_url'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (SafeUrl::outboundGateway($value) === null) {
                    $fail('网关地址必须是有效的公网 HTTP/HTTPS URL，不允许包含凭据或内网/云元数据地址。');
                }
            }];
        }
        foreach (['site_logo', 'site_favicon', 'contact_qr_image'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:2048', SafeUrl::assetRule()];
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
        $rules['refund_enabled'] = ['nullable', 'boolean'];
        $rules['backup_auto_enabled'] = ['nullable', 'boolean'];
        $rules['backup_schedule_time'] = ['nullable', 'date_format:H:i'];
        $rules['backup_retention_count'] = ['nullable', 'integer', 'min:1', 'max:365'];
        $rules['backup_retention_days'] = ['nullable', 'integer', 'min:1', 'max:3650'];
        $rules['backup_sync_directory'] = ['nullable', 'string', 'max:500'];
        $rules['backup_stale_hours'] = ['nullable', 'integer', 'min:1', 'max:8760'];
        $rules['backup_min_free_mb'] = ['nullable', 'integer', 'min:0', 'max:1048576'];
        $rules['mail_encryption'] = ['nullable', 'in:ssl,tls,none'];
        $rules['usdt_gateway'] = ['nullable', 'in:epusdt,bepusdt'];
        $rules['site_theme'] = ['nullable', \Illuminate\Validation\Rule::in(themes_available())];
        $rules['order_expire_minutes'] = ['nullable', 'integer', 'min:5', 'max:10080'];
        $rules['order_max_pending_per_ip'] = ['nullable', 'integer', 'min:1', 'max:50'];
        $rules['order_max_pending_per_email'] = ['nullable', 'integer', 'min:1', 'max:50'];
        $rules['order_max_locked_percent'] = ['nullable', 'integer', 'min:20', 'max:100'];
        $rules['popup_interval_hours'] = ['nullable', 'integer', 'min:0', 'max:8760'];
        $rules['honeypot_ban_minutes'] = ['nullable', 'integer', 'min:0', 'max:525600'];
        foreach ([
            'telegram_enabled',
            'telegram_notify_product_sold',
            'telegram_notify_product_listed',
            'telegram_target_bot_enabled',
            'telegram_target_group_enabled',
            'telegram_target_channel_enabled',
            'checkout_require_key',
            'honeypot_enabled',
            'honeypot_skip_reserved_ips',
        ] as $key) {
            $rules[$key] = ['nullable', 'boolean'];
        }
        foreach (['telegram_bot_token', 'telegram_broadcast_bot_token'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail) {
                $trimmed = trim((string) $value);
                if ($trimmed === '' || $trimmed === self::MASK) {
                    return;
                }
                if (!NotificationService::isValidBotToken($trimmed)) {
                    $fail('Telegram Bot Token 格式不正确，请填写从 @BotFather 获取的有效 Token。');
                }
            }];
        }
        $rules['telegram_chat_id'] = ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
            $invalid = NotificationService::firstInvalidTelegramTarget((string) $value, singleOnly: true);
            if ($invalid !== null) {
                $fail('站长接收会话 ID 格式不正确，请填写数字 Chat ID（如 123456789、-1001234567890#话题ID）或 @用户名。');
            }
        }];
        foreach (['telegram_target_bot_chat_ids', 'telegram_target_group_ids', 'telegram_target_channel_ids'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                $invalid = NotificationService::firstInvalidTelegramTarget((string) $value);
                if ($invalid !== null) {
                    $fail("Telegram 目标会话格式不正确（{$invalid}），请填写数字 Chat ID、-100 群组/频道 ID（支持 #话题ID）或 @频道用户名，多个用逗号或换行分隔。");
                }
            }];
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

        DB::transaction(function () use ($settingGroups, $request) {
            foreach ($settingGroups as $group => $keys) {
                foreach ($keys as $key) {
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
                    if (in_array($key, ['order_max_pending_per_ip', 'order_max_pending_per_email'], true) && $value === null) {
                        $value = '3';
                    }
                    if ($key === 'order_max_locked_percent' && $value === null) {
                        $value = '80';
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
        $data = $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => '请填写接收测试邮件的地址', 'email.email' => '邮箱格式不正确']
        );

        $result = $notifications->sendTestEmail($data['email']);

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 422);
    }

    public function testTelegram(Request $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:auto,admin,broadcast,bot,group,channel'],
            'target' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $notifications->sendTestTelegram(
            scope: (string) ($data['scope'] ?? 'auto'),
            customTarget: isset($data['target']) ? (string) $data['target'] : null,
        );

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 422);
    }
}

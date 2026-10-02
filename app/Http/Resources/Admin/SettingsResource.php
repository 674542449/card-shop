<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Unknown future settings and stored secrets never become browser fields. */
class SettingsResource extends JsonResource
{
    public const MASK = '********';
    public const KEYS = ['site_name', 'site_theme', 'site_description', 'site_logo', 'site_favicon', 'site_announcement', 'popup_announcement',
        'popup_interval_hours', 'contact_text', 'contact_url', 'contact_qr_image', 'footer_powered_by', 'site_url',
        'epay_api_url', 'epay_merchant_id', 'epay_merchant_key', 'epusdt_api_url', 'epusdt_api_token', 'usdt_gateway', 'payment_reconciliation_enabled',
        'email_template_subject', 'email_template_body', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled', 'seo_default_title', 'seo_default_description', 'seo_default_keywords', 'baidu_push_token', 'bing_indexnow_key',
        'turnstile_site_key', 'turnstile_secret_key', 'order_expire_minutes', 'honeypot_enabled', 'honeypot_ban_minutes', 'honeypot_whitelist', 'honeypot_skip_reserved_ips',
        'refund_enabled', 'backup_auto_enabled', 'backup_schedule_time', 'backup_retention_count', 'backup_retention_days', 'backup_sync_directory', 'backup_stale_hours', 'backup_min_free_mb'];

    public function toArray(Request $request): array
    {
        $data = [];
        foreach ($this->resource as $setting) {
            if (! in_array($setting->key, self::KEYS, true)) { continue; }
            $value = $setting->value;
            $data[$setting->key] = in_array($setting->key, \App\Security\SecretSettings::KEYS, true) && (string) $value !== '' ? self::MASK : $value;
        }
        $data['_available_themes'] = themes_available();
        return $data;
    }
}

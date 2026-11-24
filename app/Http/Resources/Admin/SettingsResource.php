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
        'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled',
        'telegram_broadcast_bot_token', 'telegram_notify_product_sold', 'telegram_notify_product_listed',
        'telegram_target_bot_enabled', 'telegram_target_bot_chat_ids',
        'telegram_target_group_enabled', 'telegram_target_group_ids',
        'telegram_target_channel_enabled', 'telegram_target_channel_ids',
        'seo_default_title', 'seo_default_description', 'seo_default_keywords', 'baidu_push_token', 'bing_indexnow_key',
        'turnstile_enabled', 'turnstile_site_key', 'turnstile_secret_key', 'order_expire_minutes',
        'blacklist_enabled', 'cross_site_guard_enabled', 'form_honeypot_enabled',
        'checkout_require_key', 'order_limit_enabled', 'order_max_pending_per_ip', 'order_max_pending_per_email', 'order_max_locked_percent',
        'honeypot_enabled', 'honeypot_ban_minutes', 'honeypot_whitelist', 'honeypot_skip_reserved_ips',
        'refund_enabled', 'backup_auto_enabled', 'backup_schedule_time', 'backup_retention_count', 'backup_retention_days', 'backup_sync_directory', 'backup_stale_hours', 'backup_min_free_mb',
        'backup_s3_enabled', 'backup_s3_endpoint', 'backup_s3_region', 'backup_s3_bucket', 'backup_s3_prefix', 'backup_s3_access_key', 'backup_s3_secret_key', 'backup_s3_path_style',
        'backup_r2_enabled', 'backup_r2_endpoint', 'backup_r2_region', 'backup_r2_bucket', 'backup_r2_prefix', 'backup_r2_access_key', 'backup_r2_secret_key',
        'backup_oss_enabled', 'backup_oss_endpoint', 'backup_oss_region', 'backup_oss_bucket', 'backup_oss_prefix', 'backup_oss_access_key', 'backup_oss_secret_key',
        'backup_cos_enabled', 'backup_cos_endpoint', 'backup_cos_region', 'backup_cos_bucket', 'backup_cos_prefix', 'backup_cos_access_key', 'backup_cos_secret_key'];

    public function toArray(Request $request): array
    {
        $data = [];
        foreach ($this->resource as $setting) {
            if (! in_array($setting->key, self::KEYS, true)) { continue; }
            $value = $setting->value;
            $data[$setting->key] = in_array($setting->key, \App\Security\SecretSettings::KEYS, true) && (string) $value !== '' ? self::MASK : $value;
        }
        $data['_available_themes'] = themes_available();
        $clientIp = \App\Support\IpAddress::normalize((string) $request->ip());
        $data['_client_ip'] = $clientIp;
        $data['_client_subnet64'] = \App\Support\IpAddress::subnet64($clientIp);
        return $data;
    }
}

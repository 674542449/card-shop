<?php

namespace App\Http\Controllers\Admin;

use App\Models\Setting;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SettingController extends Controller
{
    public function index()
    {
        $allSettings = Setting::all()->groupBy('group');

        $settings = [];
        foreach (Setting::all() as $setting) {
            $settings[$setting->key] = $setting->value;
        }

        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $settingGroups = [
            'site' => [
                'site_name',
                'site_description',
                'site_logo',
                'site_announcement',
            ],
            'payment' => [
                'epay_api_url',
                'epay_merchant_id',
                'epay_merchant_key',
                'epusdt_api_url',
                'epusdt_api_token',
            ],
            'email' => [
                'email_template_subject',
                'email_template_body',
            ],
            'telegram' => [
                'telegram_bot_token',
                'telegram_chat_id',
                'telegram_enabled',
            ],
            'seo' => [
                'seo_default_title',
                'seo_default_description',
                'seo_default_keywords',
                'baidu_push_token',
                'bing_indexnow_key',
            ],
            'security' => [
                'turnstile_site_key',
                'turnstile_secret_key',
                'order_expire_minutes',
            ],
        ];

        foreach ($settingGroups as $group => $keys) {
            foreach ($keys as $key) {
                if ($request->has($key)) {
                    Setting::set($key, $request->input($key), $group);
                }
            }
        }

        OperationLog::log('更新设置', 'setting', null, '更新系统设置');

        return redirect()->back()->with('success', '设置已保存。');
    }
}

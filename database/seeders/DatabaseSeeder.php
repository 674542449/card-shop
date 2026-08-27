<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedSettings();
    }

    private function seedAdmin(): void
    {
        // firstOrCreate, not updateOrCreate: this seeder runs on every container boot,
        // and updateOrCreate would silently reset the password back to the default
        // every time the stack restarts.
        Admin::firstOrCreate(
            ['username' => 'admin'],
            ['password' => Hash::make('admin888')]
        );
    }

    private function seedSettings(): void
    {
        $settings = [
            // Site settings
            'site' => [
                'site_name' => 'CardShop',
                'site_logo' => '',
                'site_description' => '',
                'site_announcement' => '',
                'contact_text' => '',
                'contact_url' => '',
                'contact_qr_image' => '',
            ],

            // Payment settings
            'payment' => [
                'epay_api_url' => '',
                'epay_merchant_id' => '',
                'epay_merchant_key' => '',
                'epusdt_api_url' => '',
                'epusdt_api_token' => '',
            ],

            // Email settings
            'email' => [
                'email_template_subject' => '【{{site_name}}】您的订单 {{order_no}} 已完成',
                'email_template_body' => <<<'TEMPLATE'
尊敬的客户，您好！

您的订单已支付成功，以下是您的订单信息：

订单编号：{{order_no}}
商品名称：{{product_name}}
购买数量：{{quantity}}
支付金额：{{total_amount}} 元

卡密信息：
{{cards}}

请妥善保管您的卡密信息。如有任何问题，请联系客服。

感谢您的购买！
{{site_name}}
TEMPLATE,
            ],

            // Telegram settings
            'telegram' => [
                'telegram_bot_token' => '',
                'telegram_chat_id' => '',
                'telegram_enabled' => '0',
            ],

            // SEO settings
            'seo' => [
                'seo_default_title' => 'CardShop',
                'seo_default_description' => '',
                'seo_default_keywords' => '',
                'baidu_push_token' => '',
                'bing_indexnow_key' => '',
            ],

            // Order settings
            'order' => [
                'order_expire_minutes' => '30',
            ],

            // Turnstile settings
            'turnstile' => [
                'turnstile_site_key' => '',
                'turnstile_secret_key' => '',
            ],
        ];

        // firstOrCreate so that values edited in the admin UI survive a container restart.
        foreach ($settings as $group => $items) {
            foreach ($items as $key => $value) {
                Setting::firstOrCreate(
                    ['key' => $key],
                    ['group' => $group, 'value' => $value]
                );
            }
        }
    }
}

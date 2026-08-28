<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedSettings();
    }

    /**
     * Create the first administrator, once.
     *
     * There is deliberately no default password. This seeder runs on every container
     * boot, the repository and the README are public, and /admin is reachable from the
     * internet — a well-known constant here means anyone who finds the host owns the
     * panel, and through it every card secret, every buyer's email, and the payment
     * gateway credentials.
     *
     * ADMIN_PASSWORD from .env is used when set. When it is not, a random one is
     * generated and printed to the boot log once, which keeps a first install usable
     * without ever shipping a password an attacker already knows.
     */
    private function seedAdmin(): void
    {
        $username = (string) (env('ADMIN_USERNAME') ?: 'admin');

        // Never touch an existing account: the operator may have changed the password,
        // and this runs on every restart.
        if (Admin::where('username', $username)->exists()) {
            return;
        }

        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = false;

        if (mb_strlen($password) < 12) {
            $password = Str::password(20, symbols: false);
            $generated = true;
        }

        Admin::create([
            'username' => $username,
            'password' => Hash::make($password),
        ]);

        if ($generated) {
            $banner = str_repeat('=', 64);
            $this->command?->getOutput()->writeln([
                '',
                $banner,
                '  管理员账号已创建 / Administrator account created',
                "  用户名 username: {$username}",
                "  密码   password: {$password}",
                '  这条信息只出现一次，请立即保存并登录后修改。',
                '  This is shown once. Save it now and change it after logging in.',
                $banner,
                '',
            ]);
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            // Site settings
            'site' => [
                'site_name' => 'CardShop',
                'site_logo' => '',
                'site_favicon' => '',
                'site_description' => '',
                'site_announcement' => '',
                'popup_announcement' => '',
                'popup_interval_hours' => '24',
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

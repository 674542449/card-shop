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
     * generated and written to storage/app/initial-admin-password.txt (mode 600),
     * which keeps a first install usable without ever shipping a password an attacker
     * already knows.
     *
     * 之前这里说的是「printed to the boot log once」。那句话把留存性讲轻了：容器的
     * stdout 会被 Docker 落盘成日志文件并长期保留，还常被集中日志系统采集，所以
     * 「一次」实际上是「永久且很多人可读」。见下面写文件那段的说明。
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
            // 密码写进受限文件，不写进 stdout。
            //
            // 这个 seeder 由 entrypoint 以容器 PID 1 的子进程身份调用，stdout 没有任何
            // 重定向，所以「打印一次」实际上等于用 Docker 的 json-file 驱动把明文密码
            // 落盘到宿主机 /var/lib/docker/containers/<id>/<id>-json.log，之后
            // `docker compose logs app` 随时能翻出来，直到日志轮转或容器重建为止。
            // 更要命的是容器 stdout 常被 Loki / ELK / CloudWatch 这类集中日志系统采集，
            // 那里的读权限通常给整个团队，范围远大于能 SSH 上宿主机的人——初始管理员
            // 密码就摆在一个几十人可全文检索的界面里。
            //
            // 写文件而不是打印，泄露面就回到「能读容器文件系统的人」，和 .env 一致。
            $path = storage_path('app/initial-admin-password.txt');
            $written = false;

            try {
                @mkdir(dirname($path), 0775, true);
                $written = file_put_contents(
                    $path,
                    "username: {$username}\npassword: {$password}\n"
                    . "读取后请立即登录修改密码，并删除本文件。\n"
                ) !== false;

                if ($written) {
                    // 只给属主读写。容器里 seeder 以 root 跑，www-data 不需要读它。
                    @chmod($path, 0600);
                }
            } catch (\Throwable) {
                $written = false;
            }

            $banner = str_repeat('=', 64);
            $lines = [
                '',
                $banner,
                '  管理员账号已创建 / Administrator account created',
                "  用户名 username: {$username}",
            ];

            if ($written) {
                $lines[] = '  密码已写入容器内文件（不打印到日志）：';
                $lines[] = '    storage/app/initial-admin-password.txt';
                $lines[] = '  读取：docker compose exec app cat storage/app/initial-admin-password.txt';
                $lines[] = '  登录后请立即修改密码，并删除该文件。';
            } else {
                // 写不进去就只能打印，否则运维拿不到密码、站点等于锁死。
                // 这种情况下明确告诉运维这条记录留在日志里了，需要自己清理。
                $lines[] = "  密码 password: {$password}";
                $lines[] = '  警告：密码文件写入失败，密码已打印到容器日志。';
                $lines[] = '  请登录修改密码后清理日志（docker compose logs 仍可读到它）。';
            }

            $lines[] = $banner;
            $lines[] = '';

            $this->command?->getOutput()->writeln($lines);
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            // Site settings
            'site' => [
                'site_name' => 'CardShop',
                // 前台模板。值必须是 resources/views/templates/ 下真实存在的目录名，
                // theme() 会校验，填了不存在的会回落 default。
                'site_theme' => 'default',
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
                // 'epusdt' (original) or 'bepusdt'. They share an endpoint and a
                // signature; only BEpusdt understands trade_type, and sending one to
                // original epusdt breaks its signature check, so this cannot be
                // guessed at runtime.
                'usdt_gateway' => 'epusdt',
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
                // Empty on purpose. Both the home page and the layout fall back to
                // site_name when this is blank, so a shop that never opens the SEO tab
                // still gets its own name in the title. Seeded as the literal
                // 'CardShop' it silently overrode site_name on the one page search
                // engines care about most, and only there — every other page builds
                // its title from site_name directly, so the mismatch was invisible
                // until you compared two tabs.
                'seo_default_title' => '',
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

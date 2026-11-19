<?php

namespace App\Console\Commands;

use App\Support\AdminPath;
use Illuminate\Console\Command;

final class ShowAdminPath extends Command
{
    protected $signature = 'admin:path
        {--raw : Output only the bare URL segment without newline decoration}
        {--regenerate : Generate and persist a new random high-strength admin path}';

    protected $description = 'Display or rotate the high-strength admin console URL path';

    public function handle(): int
    {
        $envRaw = env('ADMIN_PATH');
        $envValid = $envRaw !== null ? AdminPath::normalize((string) $envRaw) : null;

        if ($this->option('regenerate')) {
            if ($envValid !== null) {
                $newSegment = AdminPath::generate();
                $this->warn('当前后台路径由环境变量 ADMIN_PATH 显式固定，无法仅通过存储文件覆盖。');
                $this->line('请在 .env 或外部 runtime.env 中将 ADMIN_PATH 更新为以下高强度随机路径并重启服务：');
                $this->line("ADMIN_PATH={$newSegment}");

                return self::FAILURE;
            }

            $segment = AdminPath::regenerate();
            if ($this->option('raw')) {
                $this->output->write($segment);

                return self::SUCCESS;
            }

            $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
            $this->info("已重新生成随机高强度后台路径：/{$segment}");
            $this->line("登录入口：{$baseUrl}/{$segment}/login");

            return self::SUCCESS;
        }

        $segment = admin_path();
        if ($this->option('raw')) {
            $this->output->write($segment);

            return self::SUCCESS;
        }

        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $source = $envValid !== null ? '环境变量 ADMIN_PATH' : '安装自动生成（storage/app/private/admin-path）';
        $this->line("后台路径: /{$segment}");
        $this->line("登录地址: {$baseUrl}/{$segment}/login");
        $this->line("配置来源: {$source}");

        return self::SUCCESS;
    }
}

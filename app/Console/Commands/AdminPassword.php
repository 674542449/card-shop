<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminPassword extends Command
{
    protected $signature = 'admin:password
                            {username? : 管理员用户名，默认 admin}
                            {--password= : 新密码，留空则随机生成}';

    protected $description = '重置管理员密码（生产环境没有安装 tinker，用这个命令代替）';

    public function handle(): int
    {
        $username = (string) ($this->argument('username') ?: 'admin');

        $admin = Admin::where('username', $username)->first();

        if (!$admin) {
            $this->error("找不到管理员：{$username}");
            $this->line('现有账号：' . (Admin::pluck('username')->implode(', ') ?: '（无）'));

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? '');
        $generated = false;

        if ($password === '') {
            // Symbols are omitted so the value survives being copied through a shell
            // without quoting surprises.
            $password = Str::password(20, symbols: false);
            $generated = true;
        } elseif (mb_strlen($password) < 12) {
            $this->error('密码至少需要 12 个字符。');

            return self::FAILURE;
        }

        $admin->update(['password' => Hash::make($password)]);

        $this->info("管理员 {$username} 的密码已重置。");

        if ($generated) {
            $this->line('');
            $this->line(str_repeat('=', 52));
            $this->line("  新密码: {$password}");
            $this->line('  请立即保存，这条信息不会再显示。');
            $this->line(str_repeat('=', 52));
        }

        return self::SUCCESS;
    }
}

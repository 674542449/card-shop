<?php

namespace App\Console\Commands;

use App\Models\{Admin, OperationLog};
use Illuminate\Console\Command;

class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:2fa-reset {username} {--force : Skip interactive confirmation}';
    protected $description = '使用服务器控制台重置丢失的管理员验证器并让旧会话失效';

    public function handle(): int
    {
        $admin = Admin::where('username', $this->argument('username'))->first();
        if (!$admin) { $this->error('管理员不存在。'); return self::FAILURE; }
        if (!$this->option('force') && !$this->confirm('这将停用此账户的双重验证并使所有登录会话失效，继续？')) { return self::FAILURE; }
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null,
            'two_factor_revision' => bin2hex(random_bytes(16)), 'two_factor_last_counter' => null, 'two_factor_confirmed_at' => null])->save();
        OperationLog::log('重置双重验证', 'admin', $admin->id, '服务器控制台重置验证器并使旧会话失效');
        $this->info('双重验证已重置，旧会话已失效。请使用账户密码重新登录。');
        return self::SUCCESS;
    }
}

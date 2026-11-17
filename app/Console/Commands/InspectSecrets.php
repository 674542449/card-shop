<?php

namespace App\Console\Commands;

use App\Security\SecretCipher;
use App\Security\SecretSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InspectSecrets extends Command
{
    protected $signature = 'secrets:status {--json : Public key identifiers and conversion counts only}';

    protected $description = 'Check the external keyring and count legacy plaintext without revealing secrets';

    public function handle(SecretCipher $cipher): int
    {
        try {
            $metadata = $cipher->metadata();
            $legacyCards = DB::table('cards')->where('content', 'not like', SecretCipher::PREFIX.'%')->count();
            $legacySettings = DB::table('settings')->whereIn('key', SecretSettings::KEYS)
                ->whereNotNull('value')->where('value', '!=', '')
                ->where('value', 'not like', SecretCipher::PREFIX.'%')->count();
            $missingFingerprints = Schema::hasColumn('cards', 'content_fingerprint')
                ? DB::table('cards')->whereNull('content_fingerprint')->count() : DB::table('cards')->count();
            $result = ['keyring' => $metadata, 'legacy_cards' => $legacyCards,
                'legacy_settings' => $legacySettings, 'missing_card_fingerprints' => $missingFingerprints,
                'ready' => $legacyCards === 0 && $legacySettings === 0 && $missingFingerprints === 0];
            $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR)
                : 'keyring 有效；待转换卡密 '.$legacyCards.' 行、配置 '.$legacySettings.' 行、待补指纹 '.$missingFingerprints.' 行。');
            return $result['ready'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('无法验证独立 keyring 或敏感存储状态。未输出任何密钥或卡密。');
            return self::FAILURE;
        }
    }
}

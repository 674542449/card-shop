<?php

namespace App\Exceptions;

final class SecretStorageException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('敏感存储密钥不可用或密文校验失败。请检查独立 keyring 并执行 secrets:status；不能使用 APP_KEY 替代。');
    }
}

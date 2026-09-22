<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 测试环境：绕开 ThinkPHP Facade 容器初始化，手动加载 .env 并注入 env() / aes_encrypt 辅助
$_ENV_TMP = [];
if (file_exists(__DIR__ . '/../.env')) {
    foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (($p = strpos($line, '=')) !== false) {
            $_ENV_TMP[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
        }
    }
}
if (!function_exists('env')) {
    function env(?string $name = null, $default = null)
    {
        global $_ENV_TMP;
        if ($name === null) return $_ENV_TMP;
        return $_ENV_TMP[$name] ?? $default;
    }
}
if (!function_exists('app_key')) {
    function app_key(): string
    {
        $key  = (string) env('APP_KEY', '');
        $weak = 'sinan-nft-secret-key-2026';
        if ($key === '') return $weak;
        return $key;
    }
}
if (!function_exists('aes_encrypt')) {
    function aes_encrypt(string $data): string
    {
        $key = hash('sha256', app_key(), true);
        $iv  = openssl_random_pseudo_bytes(16);
        return base64_encode($iv . openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv));
    }
}
if (!function_exists('aes_decrypt')) {
    function aes_decrypt(string $encoded): ?string
    {
        $decoded = base64_decode($encoded);
        if (strlen($decoded) < 40) return null;
        $key = hash('sha256', app_key(), true);
        $iv  = substr($decoded, 0, 16);
        $ct  = substr($decoded, 16);
        $pt  = openssl_decrypt($ct, 'AES-256-CBC', $key, 0, $iv);
        return $pt !== false ? $pt : null;
    }
}

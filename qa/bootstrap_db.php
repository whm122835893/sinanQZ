<?php
/**
 * QA/SIT 脚本共享数据库连接（带环境护栏）
 *
 * 安全约定：
 * - 仓库内不存明文库口令：凭据必须经环境变量 QA_DB_USER / QA_DB_PASS 注入
 * - 必须显式 APP_ENV=sit|test|dev 才允许连接，防止误拷贝到生产机器执行清库脚本
 * - 库名 QA_DB_NAME 可覆盖（默认 sinan_nft）
 *
 * 用法（替代原先硬编码的 new PDO(...)）：
 *   require __DIR__ . '/bootstrap_db.php';   // 得到 $PDO（默认 ERRMODE_EXCEPTION）
 *   define('QA_PDO_ERRMODE', PDO::ERRMODE_WARNING); require __DIR__ . '/bootstrap_db.php';
 *   $pdo2 = qa_pdo(PDO::ERRMODE_EXCEPTION);  // 需要第二条连接时
 */

function qa_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function qa_pdo(int $errmode = PDO::ERRMODE_EXCEPTION): PDO
{
    static $cred = null;
    if ($cred === null) {
        $env = (string) qa_env('APP_ENV', '');
        if (!in_array($env, ['sit', 'test', 'dev'], true)) {
            fwrite(STDERR, "[qa] 拒绝执行：本目录脚本仅允许在 APP_ENV=sit|test|dev 环境运行（当前 APP_ENV='{$env}'），防止误操作生产数据\n");
            exit(1);
        }
        $user = qa_env('QA_DB_USER');
        $pass = qa_env('QA_DB_PASS');
        if ($user === null || $pass === null) {
            fwrite(STDERR, "[qa] 拒绝执行：请通过环境变量 QA_DB_USER / QA_DB_PASS 注入数据库凭据（仓库禁止明文口令）\n");
            exit(1);
        }
        $cred = [$user, $pass];
    }
    [$user, $pass] = $cred;
    $host = (string) qa_env('QA_DB_HOST', '127.0.0.1');
    $name = (string) qa_env('QA_DB_NAME', 'sinan_nft');
    return new PDO("mysql:host={$host};dbname={$name}", $user, $pass, [PDO::ATTR_ERRMODE => $errmode]);
}

$PDO = qa_pdo(defined('QA_PDO_ERRMODE') ? QA_PDO_ERRMODE : PDO::ERRMODE_EXCEPTION);

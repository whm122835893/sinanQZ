<?php
/**
 * QA/SIT 脚本共享数据库连接（带环境护栏）
 *
 * 安全约定：
 * - 仓库内不存明文库口令：凭据必须经环境变量 QA_DB_USER / QA_DB_PASS 注入
 * - 必须显式 APP_ENV=sit|test|dev 才允许连接，防止误拷贝到生产机器执行清库脚本
 * - 库名 QA_DB_NAME 可覆盖（默认 sinan_nft）；端口 QA_DB_PORT 可覆盖（默认 3306）
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
    $port = (int) qa_env('QA_DB_PORT', '3306');
    $name = (string) qa_env('QA_DB_NAME', 'sinan_nft');
    return new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_ERRMODE => $errmode]);
}

/**
 * 为「直接用 SQL 注入余额」的夹具补一条对应的充值流水，使全库资金恒等式
 * ∑balance = ∑recharge − ∑buy − ∑withdraw 在测试库里也成立（否则 sit_z_identity 的
 * 恒等式只能恒假，失去捕捉真实产品缺陷的能力）。
 *
 * 只处理传入的 user_id 集合，且只补「余额 − 已有流水净额」的正差额，因此不会掩盖
 * 任何其他账户的资金漂移；重复调用幂等（第二次差额为 0）。
 *
 * @param int[] $userIds 刚被夹具直接写入余额的账户
 */
function qa_seed_wallet_ledger(PDO $pdo, array $userIds): int
{
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) {
        return 0;
    }
    $in = implode(',', $ids);
    $sql = "INSERT INTO nft_wallet_transactions (user_id, trans_type, title, direction, amount, balance_after, biz_no, created_at)
            SELECT w.user_id, 'recharge', '测试资金开账', 1, d.gap, w.balance, CONCAT('QA-SEED-', w.user_id), NOW(3)
            FROM nft_wallets w
            JOIN (SELECT w2.user_id, w2.balance - COALESCE(l.net, 0) AS gap
                  FROM nft_wallets w2
                  LEFT JOIN (SELECT user_id, SUM(IF(direction = 1, amount, -amount)) AS net
                             FROM nft_wallet_transactions WHERE trans_type IN ('recharge','buy','withdraw')
                             GROUP BY user_id) l ON l.user_id = w2.user_id
                  WHERE w2.user_id IN ($in)) d ON d.user_id = w.user_id
            WHERE d.gap > 0.005";
    return $pdo->exec($sql);
}

$PDO = qa_pdo(defined('QA_PDO_ERRMODE') ? QA_PDO_ERRMODE : PDO::ERRMODE_EXCEPTION);

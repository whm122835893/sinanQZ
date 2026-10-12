<?php
/**
 * 第 6 次发版（一证一号）上线后的只读探针。
 * 只查不写：不调用 approve/backfill，只做格式化、指纹计算和一次只读 SELECT。
 * 用完即删，不进仓库、不放在 web 目录下。
 */
require '/www/wwwroot/sinan-backend/vendor/autoload.php';
$app = new \think\App('/www/wwwroot/sinan-backend/');
$app->initialize();

use app\service\RealnameService;
use think\facade\Db;

echo "== 1. 助手函数是否可用 ==\n";
echo function_exists('id_card_normalize') ? "  id_card_normalize: ok\n" : "  id_card_normalize: MISSING\n";
echo function_exists('id_card_fingerprint') ? "  id_card_fingerprint: ok\n" : "  id_card_fingerprint: MISSING\n";

echo "== 2. 归一化与指纹（同一个假证号，末位大小写）==\n";
$a = id_card_normalize(' 11010119900307757x ');
$b = id_card_normalize('11010119900307757X');
echo "  normalize A=$a B=$b 一致=" . ($a === $b ? 'yes' : 'NO') . "\n";
$fa = id_card_fingerprint('11010119900307757x');
$fb = id_card_fingerprint('11010119900307757X');
echo "  指纹长度=" . strlen($fa) . " 大小写尾位同号=" . ($fa === $fb ? 'yes' : 'NO') . "\n";
echo "  指纹前 12 位=" . substr($fa, 0, 12) . "…（不外泄完整值）\n";

echo "== 3. 新类是否加载 ==\n";
echo class_exists('app\service\IdCardBoundException') ? "  IdCardBoundException: ok\n" : "  IdCardBoundException: MISSING\n";

echo "== 4. occupiedBy 只读查询（生产应无任何已认证账号占坑）==\n";
try {
    $owner = RealnameService::occupiedBy('11010119900307757X', 0);
    echo "  返回：" . ($owner === null ? 'null（没人占坑，符合预期）' : json_encode($owner, JSON_UNESCAPED_UNICODE)) . "\n";
} catch (\Throwable $e) {
    echo "  异常：" . get_class($e) . ' / ' . $e->getMessage() . "\n";
}

echo "== 5. 列与索引现状（只读 SHOW）==\n";
$col = Db::query("SHOW COLUMNS FROM nft_users LIKE 'id_card_hash'");
echo "  列：" . json_encode($col[0] ?? 'MISSING') . "\n";
$idx = Db::query("SHOW INDEX FROM nft_users WHERE Key_name='idx_id_card_hash'");
echo "  索引：" . ($idx ? 'idx_id_card_hash 存在，NON_UNIQUE=' . $idx[0]['Non_unique'] : 'idx_id_card_hash 不存在') . "\n";

echo "== 6. 审核模式现值（只读）==\n";
echo "  realname_audit_mode = " . (Db::name('system_configs')->where('config_key', 'realname_audit_mode')->value('config_value') ?: '(无行)') . "\n";

echo "== 7. 账号状态分布（只读）==\n";
foreach (Db::query("SELECT realname_status, COUNT(*) c, SUM(id_card_hash IS NOT NULL) hashed FROM nft_users GROUP BY realname_status") as $r) {
    echo "  status={$r['realname_status']} 账号数={$r['c']} 已带指纹={$r['hashed']}\n";
}

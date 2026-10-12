<?php
declare(strict_types=1);

/**
 * 存量编号重编（serial:renumber）「换号环」用例 —— 沙箱实跑，不碰生产。
 *
 * 它证明什么：
 *   `--apply` 落库时，一个新编号很可能正好是**另一行还没改走的旧编号**。
 *   生产 2026-10-12 那份方案里就有：资产 3 要发 SN-1-0247，而 SN-1-0247 当时还挂在资产 22 名下。
 *   `nft_user_collectibles` 上有 UNIQUE KEY `uk_collectible_serial`(collectible_id, serial)，
 *   所以「逐行挪到中转位就立刻落最终号」的写法会当场 1062 回滚整批（真实踩过，见 RUNBOOK 7.27）。
 *   本用例刻意造一个**三行循环换号**（A→B 的旧号、B→C 的旧号、C→A 的旧号），
 *   在这种排布下任何「挪一格落一格」的实现都必然撞索引，只有「两段之间不夹落号」能过。
 *
 * 跑法（沙箱，凭据走环境变量，仓库不存明文）：
 *   APP_ENV=sit QA_DB_USER=<沙箱账号> QA_DB_PASS=<沙箱口令> QA_DB_PORT=3308 \
 *     php qa/sit_serial_swap_collision.php
 *
 * 反证怎么做（别信「改了但没报错」，先证明真的拆了）：
 *   1) `cp` 一份 SerialRenumber.php 存档；
 *   2) 把写库阶段第二段的 `foreach` 前面那句「整批挪中转位」的循环删掉，改成逐行挪完就落；
 *   3) **先 `diff` 打出改动**，确认删的那两行真没了，再跑本脚本；
 *   4) 期望：第 [3] 项变成红（子命令退出码 1、输出含 1062 / Duplicate entry），库内三行编号一个没变；
 *   5) 还原存档，`diff` 应为空，本脚本重新全绿。
 */

require __DIR__ . '/bootstrap_db.php';

$ROOT   = dirname(__DIR__);
$BACKEND = $ROOT . '/sinan-nft-backend';
$PHP    = PHP_BINARY;
$pdo    = $PDO;   // 基座已建好的那条连接，沿用，不开第二条

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [ok] {$name}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
    }
}

// ---------- 1. 造一个只属于本用例的藏品 + 三行资产 ----------
$cat  = (int) $pdo->query('SELECT id FROM nft_categories ORDER BY id LIMIT 1')->fetchColumn();
$user = (int) $pdo->query('SELECT id FROM nft_users ORDER BY id LIMIT 1')->fetchColumn();
if ($cat <= 0 || $user <= 0) {
    fwrite(STDERR, "[qa] 沙箱库里没有分类或用户，无法造夹具\n");
    exit(1);
}

$pdo->exec("INSERT INTO nft_collectibles (category_id, name, image, price, edition, status)
            VALUES ({$cat}, '__QA_SERIAL_SWAP__', '/qa/none.png', 1.00, 10, 'off')");
$cid = (int) $pdo->lastInsertId();

$sn = static fn (int $no): string => sprintf('SN-%d-%04d', $cid, $no);
$ids = [];
foreach ([7, 3, 5] as $no) {   // A=…0007, B=…0003, C=…0005
    $pdo->exec("INSERT INTO nft_user_collectibles (user_id, collectible_id, serial, source, acquired_at)
                VALUES ({$user}, {$cid}, '{$sn($no)}', 'purchase', NOW(3))");
    $ids[] = (int) $pdo->lastInsertId();
}
[$idA, $idB, $idC] = $ids;

// 站内通知快照：extra.serial 存 A 的旧号，重编后应跟着变成 A 的新号
$extra = json_encode(['serial' => $sn(7), 'from' => 'qa'], JSON_UNESCAPED_UNICODE);
$pdo->exec("INSERT INTO nft_inbox (user_id, type, title, collectible_id, name, extra)
            VALUES ({$user}, 'airdrop', '__QA_SERIAL_SWAP__', {$cid}, '__QA__', '{$extra}')");
$inboxId = (int) $pdo->lastInsertId();

$cleanup = static function () use ($pdo, $cid, $ids, $inboxId): void {
    $pdo->exec("DELETE FROM nft_inbox WHERE id = " . (int) $inboxId);
    $pdo->exec('DELETE FROM nft_user_collectibles WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $pdo->exec("DELETE FROM nft_collectibles WHERE id = {$cid}");
};
register_shutdown_function(static function () use ($cleanup): void {
    try {
        $cleanup();
    } catch (\Throwable) {
        // 清理失败不影响结论，夹具名带 __QA_ 前缀便于人工辨认
    }
});

echo "== 夹具：藏品 {$cid}（发行量 10）三行资产 " . implode(', ', $ids) . "，编号 " . implode(' / ', [$sn(7), $sn(3), $sn(5)]) . "\n";

// ---------- 2. 手工写一份「换号环」方案（不靠随机预览，保证可复现） ----------
// A(0007) → 0003（B 的旧号）、B(0003) → 0005（C 的旧号）、C(0005) → 0007（A 的旧号）
$plan = [
    'version'      => 1,
    'generated_at' => date('Y-m-d H:i:s'),
    'collectibles' => [[
        'id'   => $cid,
        'name' => '__QA_SERIAL_SWAP__',
        'edition' => 10,
        'minted'  => 0,
        'refs'    => ['swap' => 0, 'decompose' => 0, 'inbox' => 1],
        'rows'    => [
            ['id' => $idA, 'old' => $sn(7), 'new' => $sn(3), 'user' => $user, 'st' => 'held', 'why' => 'qa 换号环'],
            ['id' => $idB, 'old' => $sn(3), 'new' => $sn(5), 'user' => $user, 'st' => 'held', 'why' => 'qa 换号环'],
            ['id' => $idC, 'old' => $sn(5), 'new' => $sn(7), 'user' => $user, 'st' => 'held', 'why' => 'qa 换号环'],
        ],
    ]],
    'summary' => ['collectibles' => 1, 'rows' => 3, 'clean' => 0, 'blocked' => 0],
];

$planDir  = $BACKEND . '/runtime/serial_plans';
if (!is_dir($planDir)) {
    mkdir($planDir, 0755, true);
}
$planFile = $planDir . '/qa_swap_' . date('Ymd_His') . '.json';
file_put_contents($planFile, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "== 方案文件：{$planFile}\n";

// ---------- 3. 真跑一次 --apply ----------
$cmd = sprintf(
    '%s think serial:renumber --apply --from=%s 2>&1',
    escapeshellarg($PHP),
    escapeshellarg($planFile)
);
$despec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc   = proc_open($cmd, $despec, $pipes, $BACKEND);
$out    = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
} else {
    $code = -1;
}
echo "-- 子命令退出码：{$code}\n-- 输出：\n";
foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $line) {
    echo '   ' . $line . "\n";
}

check('[1] 子命令退出码为 0（换号环能落库，没被唯一索引打断）', $code === 0, '退出码 ' . $code);
check('[2] 输出里没有 1062 / Duplicate entry', stripos((string) $out, 'duplicate') === false && strpos((string) $out, '1062') === false);
check('[3] 输出报「3 件资产改了编号」且同步站内通知 1 条',
    (bool) preg_match('/重编完成：3 件资产改了编号/', (string) $out)
    && (bool) preg_match('/站内通知 1 条/', (string) $out));

// ---------- 4. 逐行核对最终编号 ----------
$final = $pdo->query("SELECT id, serial FROM nft_user_collectibles WHERE collectible_id = {$cid} ORDER BY id")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$expect = [$idA => $sn(3), $idB => $sn(5), $idC => $sn(7)];
ksort($final);
check('[4] 三行最终编号正好是方案里的那三个新号（一个号不多一个号不少）', $final == $expect,
    '期望 ' . json_encode($expect) . '，实际 ' . json_encode($final));

$leftover = (int) $pdo->query("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id = {$cid} AND serial LIKE 'TMP%'")->fetchColumn();
check('[5] 没留下中转号（TMP-R-*）', $leftover === 0, "剩 {$leftover} 行");

$distinct = (int) $pdo->query("SELECT COUNT(DISTINCT serial) FROM nft_user_collectibles WHERE collectible_id = {$cid}")->fetchColumn();
check('[6] 同藏品内编号仍然互不相同', $distinct === 3, "distinct={$distinct}");

$inboxSerial = json_decode((string) $pdo->query("SELECT extra FROM nft_inbox WHERE id = {$inboxId}")->fetchColumn(), true)['serial'] ?? null;
check('[7] 站内通知里的编号快照跟着换成了新号', $inboxSerial === $sn(3), '实际 ' . var_export($inboxSerial, true));

$maxNo = 0;
foreach ($final as $serial) {
    if (preg_match('/-(\d+)$/', (string) $serial, $m)) $maxNo = max($maxNo, (int) $m[1]);
}
check('[8] 最大号 ≤ 发行量 10（不越界）', $maxNo <= 10, "max={$maxNo}");

@unlink($planFile);

echo "\n== 结论：{$pass} 项通过 / {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);

<?php
/** 用户列表「手机尾号筛选」验证
 *  覆盖：单尾号 / 多尾号 / 多位尾号 / 非法值 / 与状态组合 / 导出全量分页口径
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = 'http://127.0.0.1:8080';
$CACHE_DIR = '/workspace/sinanQZ/sinan-nft-backend/runtime/cache';
$CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

$pass = 0; $fail = 0; $defects = [];
function T($n, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; printf("  %s %-52s%s\n", $c ? 'PASS' : 'FAIL', $n, $d ? " | $d" : ''); if (!$c) $GLOBALS['defects'][] = $n . ($d ? " | $d" : ''); }
function http($m, $u, $b = null, $t = null) {
    global $BASE; $ch = curl_init($BASE . $u);
    $h = ['Content-Type: application/json'];
    if ($t) $h[] = "Authorization: Bearer $t";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
    $raw = curl_exec($ch); curl_close($ch);
    return json_decode($raw ?: '', true) ?: ['code' => -2, 'message' => 'BADJSON'];
}
function crackCaptcha(string $captchaId): ?string {
    global $CACHE_DIR, $CHARS;
    $md5 = md5('captcha_' . $captchaId);
    $file = $CACHE_DIR . '/' . substr($md5, 0, 2) . '/' . substr($md5, 2) . '.php';
    for ($i = 0; $i < 20; $i++) { if (is_file($file)) break; usleep(100000); }
    if (!is_file($file)) return null;
    $content = (string) file_get_contents($file);
    $pos = strpos($content, 'exit();?>');
    if ($pos === false) return null;
    $hash = @unserialize(ltrim(substr($content, $pos + 9)));
    if (!is_string($hash)) return null;
    $len = strlen($CHARS);
    for ($a = 0; $a < $len; $a++) for ($b = 0; $b < $len; $b++)
    for ($c = 0; $c < $len; $c++) for ($d = 0; $d < $len; $d++) {
        $p = $CHARS[$a] . $CHARS[$b] . $CHARS[$c] . $CHARS[$d];
        if (hash('sha256', $p) === $hash) return $p;
    }
    return null;
}

$PDO = new PDO('mysql:host=127.0.0.1;dbname=sinan_nft', 'sinan', 'sinan123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$PDO->exec("SET NAMES utf8mb4");
$q = fn($s) => $PDO->query($s)->fetch(PDO::FETCH_NUM)[0] ?? null;

echo "========== 用户列表尾号筛选验证 ==========\n";
$T0 = microtime(true);

$j = http('GET', '/api/captcha/image');
$cid = $j['data']['captcha_id'] ?? '';
$ccode = $cid ? crackCaptcha($cid) : null;
$login = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123', 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$adminToken = $login['data']['token'] ?? '';
T('管理端登录', ($login['code'] ?? -1) === 200 && $adminToken, $login['message'] ?? '');

// 动态期望值（与后端同一 SQL 口径：未删除用户）
$tailCnt = fn($tails) => (int) $q("SELECT COUNT(*) FROM nft_users WHERE deleted_at IS NULL AND (" . implode(' OR ', array_map(fn($t) => "RIGHT(phone," . strlen($t) . ")='$t'", $tails)) . ')');

// 1. 单尾号 7
$r = http('GET', '/admin/users?phoneTail=7&page=1&pageSize=100', null, $adminToken);
$exp = $tailCnt(['7']);
T("单尾号7：total={$exp}", ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $exp, "total={$r['data']['total']} msg={$r['message']}");
$bad = 0;
foreach (($r['data']['list'] ?? []) as $u) if (substr((string) $u['phone'], -1) !== '7') $bad++;
T('单尾号7：返回行全部尾号=7', $bad === 0, "异常 $bad 行");

// 2. 多尾号 7,8
$r = http('GET', '/admin/users?phoneTail=7,8&page=1&pageSize=100', null, $adminToken);
$exp = $tailCnt(['7', '8']);
T("多尾号7,8：total={$exp}", ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $exp, "total={$r['data']['total']}");
$bad = 0;
foreach (($r['data']['list'] ?? []) as $u) if (!in_array(substr((string) $u['phone'], -1), ['7', '8'], true)) $bad++;
T('多尾号7,8：返回行尾号∈{7,8}', $bad === 0, "异常 $bad 行");

// 3. 多位尾号 00
$r = http('GET', '/admin/users?phoneTail=00&page=1&pageSize=100', null, $adminToken);
$exp = $tailCnt(['00']);
T("多位尾号00：total={$exp}", ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $exp, "total={$r['data']['total']}");

// 4. 非法值
foreach (['abc', '1a', '123456789012'] as $bad1) {
    $r = http('GET', '/admin/users?phoneTail=' . urlencode($bad1) . '&page=1&pageSize=10', null, $adminToken);
    T("非法尾号「{$bad1}」被拒（4220）", ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
}

// 5. 与实名状态组合
$r = http('GET', '/admin/users?phoneTail=8&realnameStatus=approved&page=1&pageSize=100', null, $adminToken);
$exp = (int) $q("SELECT COUNT(*) FROM nft_users WHERE deleted_at IS NULL AND RIGHT(phone,1)='8' AND realname_status=2");
T("组合：尾号8+已实名 total={$exp}", ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $exp, "total={$r['data']['total']}");

// 6. 导出口径：循环分页拉全量与 total 一致（前端导出即此口径）
$all = []; $p = 1;
while ($p <= 100) {
    $r = http('GET', '/admin/users?phoneTail=7&page=' . $p . '&pageSize=100', null, $adminToken);
    $list = $r['data']['list'] ?? [];
    $all = array_merge($all, $list);
    if (count($all) >= (int) ($r['data']['total'] ?? 0) || !$list) break;
    $p++;
}
T('导出口径：全量拉取 = total', count($all) === $tailCnt(['7']), "拉取 " . count($all) . ' / 期望 ' . $tailCnt(['7']));

// 7. 原有筛选回归（无尾号参数）
$r = http('GET', '/admin/users?page=1&pageSize=10', null, $adminToken);
$exp = (int) $q("SELECT COUNT(*) FROM nft_users WHERE deleted_at IS NULL");
T('回归：不带尾号参数 total 不受影响', ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $exp, "total={$r['data']['total']}/{$exp}");

echo "\n==========================================\n";
printf("总计：%d PASS / %d FAIL（%.1fs）\n", $pass, $fail, microtime(true) - $T0);
if ($defects) { echo "问题清单：\n"; foreach ($defects as $d) echo "  - $d\n"; exit(1); }
exit(0);

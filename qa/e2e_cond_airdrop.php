<?php
/** 空投管理 E2E 验证（活动空投：条件筛选 / 名单快照 / 批量发放 / 限量 / 编辑删除约束 / C端可见 / 审计）
 *  前置：后端 127.0.0.1:8080、MySQL sinan_nft（通过 DB_HOST/DB_NAME/DB_USER/DB_PASS 环境变量传入）
 *  造数：尾号7×3（含1黑名单）、尾号8×2（尾八A持有龙纹罗盘）、尾号9×1（已删除）
 *  期望值动态计算（与后端同一 SQL 口径），避免与库内既有用户尾号冲突
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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
    $raw = curl_exec($ch); curl_close($ch);
    return json_decode($raw ?: '', true) ?: ['code' => -2, 'message' => 'BADJSON'];
}
function qs(array $p): string { return '?' . http_build_query($p); }
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
function getCaptcha(): array {
    $j = http('GET', '/api/captcha/image');
    if (($j['code'] ?? -1) !== 0) return [null, null, 'captcha/image 失败'];
    $id = $j['data']['captcha_id'] ?? '';
    $code = $id ? crackCaptcha($id) : null;
    return [$id, $code, $code ? null : '破解失败'];
}

$PDO = new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';dbname='.(getenv('DB_NAME')?:'sinan_nft'), getenv('DB_USER')?:'sinan', getenv('DB_PASS')?:'', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$PDO->exec("SET NAMES utf8mb4");
$q = fn($s) => $PDO->query($s)->fetch(PDO::FETCH_NUM)[0] ?? null;

echo "========== 阶段一：管理端登录 ==========\n";
$T0 = microtime(true);
[$cid, $ccode, $cerr] = getCaptcha();
T('管理端图形验证码获取+破解', $cid && $ccode, $cerr ?: '');
$login = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123', 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$adminToken = $login['data']['token'] ?? '';
T('管理端登录', ($login['code'] ?? -1) === 200 && $adminToken, $login['message'] ?? '');

echo "\n========== 阶段二：测试造数（尾号7/8/9 可控分布） ==========\n";
$now = date('Y-m-d H:i:s');
$yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
$pwd = password_hash('Pass#2026', PASSWORD_BCRYPT);
// 清理历史 E2E 数据（顺序：活动关联 records/名单/任务 → 活动 → 藏品关联 → 藏品 → 用户，规避外键 RESTRICT）
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_airdrop_activities a ON a.id=r.activity_id WHERE a.name LIKE '空投管理E2E%'");
$PDO->exec("DELETE e FROM nft_airdrop_eligibilities e JOIN nft_airdrop_activities a ON a.id=e.activity_id WHERE a.name LIKE '空投管理E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_airdrop_activities a ON a.id=t.target_id WHERE t.target_type=3 AND a.name LIKE '空投管理E2E%'");
$PDO->exec("DELETE FROM nft_airdrop_activities WHERE name LIKE '空投管理E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_collectibles c ON c.id=t.target_id WHERE t.target_type IN (1,2) AND c.name='空投管理E2E藏品'");
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_collectibles c ON c.id=r.collectible_id WHERE c.name='空投管理E2E藏品'");
$PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_collectibles c ON c.id=uc.collectible_id WHERE c.name='空投管理E2E藏品'");
$PDO->exec("DELETE i FROM nft_inbox i JOIN nft_collectibles c ON c.id=i.collectible_id WHERE c.name='空投管理E2E藏品'");
$PDO->exec("DELETE FROM nft_collectibles WHERE name='空投管理E2E藏品'");
foreach (["'1397%'", "'1398%'", "'1399%'", "'1391%'", "'1392%'", "'1393%'"] as $like) {
    $PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_users u ON u.id=r.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE i FROM nft_inbox i JOIN nft_users u ON u.id=i.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE FROM nft_users WHERE phone LIKE $like");
}
$seedUsers = [
    ['13977770007', '尾七A', 1, 0, $now],          // 尾号7 实名通过 今天注册
    ['13977770017', '尾七B', 1, 0, $yesterday],    // 尾号7 实名通过 昨天注册
    ['13977770027', '尾七C', 0, 1, $now],          // 尾号7 黑名单 → 排除
    ['13988880008', '尾八A', 1, 0, $now],          // 尾号8 实名通过（持有龙纹罗盘）
    ['13988880018', '尾八B', 0, 0, $now],          // 尾号8 未实名
    ['13999990009', '尾九A', 1, 0, $now],          // 尾号9（软删除 → 排除）
];
$st = $PDO->prepare("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,realname_status,password,status,is_blacklisted,deleted_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($seedUsers as $i => $u) {
    $st->execute([$u[0], $u[1], '', 'E' . str_pad((string)($i + 1), 5, '0', STR_PAD_LEFT), 'EC' . $i . 'X', $u[2], $u[2], $pwd, 1, 0, null, $u[4], $u[4]]);
}
$PDO->exec("UPDATE nft_users SET deleted_at='$now' WHERE phone='13999990009'");     // 尾九A → 软删除
$PDO->exec("UPDATE nft_users SET is_blacklisted=1 WHERE phone='13977770027'");      // 尾七C → 黑名单

// 动态期望值（与后端筛选同一 SQL 口径）
$validTail = fn($t) => (int) $q("SELECT COUNT(*) FROM nft_users WHERE phone REGEXP '^1[0-9]{9}$t$' AND deleted_at IS NULL AND is_blacklisted=0");
$t7 = $validTail('7');
$t8 = $validTail('8');
T('造数校验：有效尾号7用户≥2（含黑名单排除）', $t7 >= 2, "实际 $t7");
T('造数校验：有效尾号8用户=2', $t8 === 2, "实际 $t8");

// 给尾八A 插 1 份已有藏品持仓（hold 型活动名单依据）
$holdCid = (int) $q("SELECT id FROM nft_collectibles WHERE name='龙纹罗盘' LIMIT 1");
$tail8AId = (int) $q("SELECT id FROM nft_users WHERE phone='13988880008'");
$PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at) VALUES ($tail8AId,$holdCid,'SN-TST-0001','airdrop',0,'$now','held','$now','$now')");
$holdN = (int) $q("SELECT COUNT(*) FROM nft_users u WHERE u.deleted_at IS NULL AND u.is_blacklisted=0 AND EXISTS (SELECT 1 FROM nft_user_collectibles uc WHERE uc.user_id=u.id AND uc.collectible_id=$holdCid AND uc.status IN ('held','consigned','frozen'))");
T('造数校验：有效持有龙纹罗盘=1（尾八A）', $holdN === 1, "实际 $holdN");

echo "\n========== 阶段三：创建空投藏品（edition=50） ==========\n";
$catId = (int) $q("SELECT id FROM nft_categories LIMIT 1");
$r = http('POST', '/admin/collectibles', [
    'name' => '空投管理E2E藏品', 'category_id' => $catId, 'image' => '/images/collections/cover-1.jpg',
    'price' => 10, 'edition' => 50, 'per_user_limit' => 10, 'issuer' => 'E2E', 'description' => '空投管理验证',
], $adminToken);
$airCid = (int) ($r['data']['id'] ?? 0);
T('后台创建空投藏品', ($r['code'] ?? -1) === 200 && $airCid > 0, $r['message'] ?? '');
$r = http('POST', "/admin/collectibles/$airCid/release", ['status' => 'onsale'], $adminToken);
T('后台上架（库存池=50）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

echo "\n========== 阶段四：空投活动创建校验 ==========\n";
$mkAct = fn(array $b) => http('POST', '/admin/marketing/airdrop', $b, $adminToken);
$r = $mkAct(['name' => '空投管理E2E-无条件', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => []]);
T('校验：无条件被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '空投管理E2E-非法尾号', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['phone_tails' => ['7a']]]);
T('校验：非法尾号被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '空投管理E2E-超长尾号', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['phone_tails' => ['12345678901234']]]);
T('校验：超长尾号被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '空投管理E2E-无快照', 'type' => 'hold', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1]);
T('校验：hold型缺快照藏品被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '空投管理E2E-藏品不存在', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => 99999999, 'quantity_per_user' => 1, 'condition_config' => ['phone_tails' => ['7']]]);
T('校验：藏品不存在被拒（4040）', ($r['code'] ?? -1) === 4040, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '空投管理E2E-份数越界', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 101, 'condition_config' => ['phone_tails' => ['7']]]);
T('校验：每人份数>100被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 主活动 A：尾号7 × 每人2份 × 进行中
$r = $mkAct(['name' => '空投管理E2E-尾号7', 'type' => 'condition', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 2, 'start_time' => '2026-01-01 00:00:00', 'condition_config' => ['phone_tails' => ['7']]]);
$actA = (int) ($r['data']['id'] ?? 0);
T('创建：条件型活动A（尾号7×2份·进行中）', ($r['code'] ?? -1) === 200 && $actA > 0, "id=$actA msg={$r['message']}");
$r = http('GET', '/admin/marketing/airdrop' . qs(['keyword' => '空投管理E2E-尾号7', 'page' => 1, 'pageSize' => 10]), null, $adminToken);
$rowA = null;
foreach (($r['data']['list'] ?? []) as $row) if ((int) $row['id'] === $actA) $rowA = $row;
$cfgTails = array_map('strval', $rowA['conditionConfig']['phoneTails'] ?? []);
T('列表：活动A可见且条件回显（驼峰键）', $rowA && $rowA['type'] === 'condition' && $cfgTails === ['7'] && (int) $rowA['quantityPerUser'] === 2 && $rowA['status'] === 'active', $rowA ? json_encode($rowA['conditionConfig'], JSON_UNESCAPED_UNICODE) : '未找到');

echo "\n========== 阶段五：资格名单生成与查看 ==========\n";
$gen = fn($id) => http('POST', '/admin/marketing/airdrop/eligibility-generate', ['activity_id' => $id], $adminToken);
$elig = fn($id, $st = '') => http('GET', '/admin/marketing/airdrop/eligibilities' . qs(array_filter(['activity_id' => $id, 'status' => $st, 'page' => 1, 'pageSize' => 100])), null, $adminToken);

// 5.1 A 生成 → 有效尾号7（黑名单尾七C/软删除尾九A排除）
$r = $gen($actA);
T("名单：A生成尾号7→{$t7}人", ($r['code'] ?? -1) === 200 && (int) ($r['data']['generated'] ?? -1) === $t7, "generated={$r['data']['generated']} msg={$r['message']}");
// 5.2 名单查看：全部待发放 + 手机号脱敏 + 名单成员核对
$r = $elig($actA);
$phones = array_column($r['data']['list'] ?? [], 'phone');
$masked = !in_array(false, array_map(fn($p) => strpos((string) $p, '****') !== false, $phones), true);
$allElig = !in_array('eligible', array_diff(array_column($r['data']['list'] ?? [], 'status'), ['eligible']), true);
$hasA = in_array('139****0007', $phones, true) || in_array('13977770007', $phones, true);
$hasB = in_array('139****0017', $phones, true) || in_array('13977770017', $phones, true);
$noC = !in_array('139****0027', $phones, true);
T("名单：A共{$t7}条待发放", ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? -1) === $t7, "total={$r['data']['total']}");
T('名单：手机号脱敏', $masked && $phones, implode(',', $phones));
T('名单：状态均为待发放', $allElig, '');
T('名单：尾七A/尾七B在列、黑名单尾七C排除', $hasA && $hasB && $noC, json_encode($phones));
$r = $elig($actA, 'issued');
T('名单：状态筛选issued=0', (int) ($r['data']['total'] ?? -1) === 0, "total={$r['data']['total']}");
// 5.3 B：多尾号（字符串兼容形式 7,8）→ t7+t8 人，draft 状态
$r = $mkAct(['name' => '空投管理E2E-多尾号', 'type' => 'condition', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['phone_tails' => '7,8']]);
$actB = (int) ($r['data']['id'] ?? 0);
T('创建：多尾号活动B（7,8·草稿）', ($r['code'] ?? -1) === 200 && $actB > 0, "id=$actB");
$r = $gen($actB);
T('名单：B生成尾号7,8→' . ($t7 + $t8) . '人', (int) ($r['data']['generated'] ?? -1) === $t7 + $t8, "generated={$r['data']['generated']}");
// 5.4 G：checkin 实时类型 → 拒绝生成
$r = $mkAct(['name' => '空投管理E2E-签到型', 'type' => 'checkin', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'checkin_days' => 2]);
$actG = (int) ($r['data']['id'] ?? 0);
$r = $gen($actG);
T('名单：实时类型(checkin)拒绝生成（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 5.5 E：hold 型 → 持有龙纹罗盘（尾八A）
$r = $mkAct(['name' => '空投管理E2E-持有快照', 'type' => 'hold', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'snapshot_collectible_id' => $holdCid]);
$actE = (int) ($r['data']['id'] ?? 0);
$r = $gen($actE);
T("名单：hold型生成→{$holdN}人（尾八A）", (int) ($r['data']['generated'] ?? -1) === $holdN, "generated={$r['data']['generated']}");
// 5.6 F：direct 型 → 全部有效用户
$r = $mkAct(['name' => '空投管理E2E-全量直投', 'type' => 'direct', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1]);
$actF = (int) ($r['data']['id'] ?? 0);
$validCnt = (int) $q("SELECT COUNT(*) FROM nft_users WHERE deleted_at IS NULL AND is_blacklisted=0");
$r = $gen($actF);
T("名单：direct型生成→全部有效用户（{$validCnt}）", (int) ($r['data']['generated'] ?? -1) === $validCnt, "generated={$r['data']['generated']}");
// 5.7 边界：不存在活动
$r = $gen(99999999);
T('名单：活动不存在（4040）', ($r['code'] ?? -1) === 4040, "code={$r['code']}");

echo "\n========== 阶段六：批量发放（含限量/库存约束） ==========\n";
$issue = fn($id) => http('POST', '/admin/marketing/airdrop/issue', ['activity_id' => $id], $adminToken);
$poolOf = fn() => (int) $q("SELECT edition-sold-locked_quantity-reserved_count-airdropped_count-destroyed_count FROM nft_collectibles WHERE id=" . $GLOBALS['airCid']);
$GLOBALS['airCid'] = $airCid;
// 6.1 草稿状态发放被拒
$r = $issue($actB);
T('发放：草稿活动被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 6.2 A 首次发放 → t7人×2份
$r = $issue($actA);
$taskNoA = (string) ($r['data']['task_no'] ?? '');
T("发放：A首轮{$t7}人×2份", ($r['code'] ?? -1) === 200 && (int) ($r['data']['issued'] ?? 0) === $t7 && strpos($taskNoA, 'ADA') === 0, "issued={$r['data']['issued']} task=$taskNoA msg={$r['message']}");
// 6.3 落库核对
$recA = (int) $q("SELECT COUNT(*) FROM nft_airdrop_records WHERE activity_id=$actA AND status='issued'");
T("落库：A空投记录" . (2 * $t7) . "条", $recA === 2 * $t7, "实际 $recA");
$ucCnt = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=$airCid AND source='airdrop' AND status='held'");
T("落库：持仓" . (2 * $t7) . "份（source=airdrop）", $ucCnt === 2 * $t7, "实际 $ucCnt");
$eligStat = $PDO->query("SELECT SUM(status='eligible') e, SUM(status='issued') i FROM nft_airdrop_eligibilities WHERE activity_id=$actA")->fetch(PDO::FETCH_ASSOC);
T("落库：A名单 issued={$t7}/eligible=0", (int) $eligStat['i'] === $t7 && (int) $eligStat['e'] === 0, json_encode($eligStat));
$row = $PDO->query("SELECT airdropped_count, circulate FROM nft_collectibles WHERE id=$airCid")->fetch(PDO::FETCH_ASSOC);
T('库存恒等式：airdropped/circulate=' . (2 * $t7), (int) $row['airdropped_count'] === 2 * $t7 && (int) $row['circulate'] === 2 * $t7, json_encode($row));
T('库存恒等式：池=' . (50 - 2 * $t7), $poolOf() === 50 - 2 * $t7, '实际 ' . $poolOf());
$inboxCnt = (int) $q("SELECT COUNT(*) FROM nft_inbox i JOIN nft_users u ON u.id=i.user_id WHERE i.type='airdrop' AND i.collectible_id=$airCid AND u.phone IN ('13977770007','13977770017')");
T('收件箱：尾七A/尾七B收到通知', $inboxCnt === 2, "实际 $inboxCnt");
$actRow = $PDO->query("SELECT issued_count FROM nft_airdrop_activities WHERE id=$actA")->fetch(PDO::FETCH_NUM);
T("落库：活动A issued_count=" . (2 * $t7), (int) $actRow[0] === 2 * $t7, "实际 {$actRow[0]}");
// 6.4 空投任务与明细（target_type=3 空投活动）
$task = $PDO->query("SELECT id,total_quantity,user_count,success_count FROM nft_airdrop_tasks WHERE task_no='$taskNoA'")->fetch(PDO::FETCH_ASSOC);
$taskOk = $task && (int) $task['total_quantity'] === 2 * $t7 && (int) $task['user_count'] === $t7 && (int) $task['success_count'] === 2 * $t7;
T('任务：ADA任务落库（人数/份数/成功数）', $taskOk, json_encode($task));
$r = http('GET', '/admin/marketing/airdrop-tasks' . qs(['keyword' => '空投管理E2E-尾号7', 'page' => 1, 'pageSize' => 10]), null, $adminToken);
$taskInList = null;
foreach (($r['data']['list'] ?? []) as $t) if ($t['taskNo'] === $taskNoA) $taskInList = $t;
T('任务列表：ADA任务可见且标注「空投活动」', $taskInList && ($taskInList['targetTypeLabel'] ?? '') === '空投活动', $taskInList ? $taskInList['targetTypeLabel'] : '未找到');
if ($task) {
    $r = http('GET', "/admin/marketing/airdrop-tasks/{$task['id']}/records?page=1&pageSize=100", null, $adminToken);
    T('任务明细：' . (2 * $t7) . '条发放记录', (int) ($r['data']['total'] ?? -1) === 2 * $t7, "total={$r['data']['total']}");
}
// 6.5 无待发放时再发 → 拒绝
$r = $issue($actA);
T('发放：无待发放被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 6.6 新增尾号7用户尾七D → 重新生成名单（保留已发放，新增待发放）→ 再发一轮
$st->execute(['13977770037', '尾七D', '', 'E00007', 'ECD7X', 1, 1, $pwd, 1, 0, null, $now, $now]);
$r = $gen($actA);
T("名单：A重新生成（保留已发放{$t7}·新增尾七D 1人）", ($r['code'] ?? -1) === 200 && (int) ($r['data']['generated'] ?? -1) === 1 && (int) ($r['data']['kept_issued'] ?? -1) === $t7, "gen={$r['data']['generated']} kept={$r['data']['kept_issued']} msg={$r['message']}");
$r = $issue($actA);
T('发放：A次轮仅新增尾七D获发（1人×2份）', ($r['code'] ?? -1) === 200 && (int) ($r['data']['issued'] ?? 0) === 1, "issued={$r['data']['issued']} msg={$r['message']}");
$ucCnt2 = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=$airCid AND source='airdrop'");
$poolAfterA = 50 - (2 * $t7 + 2);
T('落库：A累计' . (2 * $t7 + 2) . '份/池=' . $poolAfterA, $ucCnt2 === 2 * $t7 + 2 && $poolOf() === $poolAfterA, "持仓 $ucCnt2 池 " . $poolOf());
// 6.7 C：总限量裁切（limit=2, 每人2份 → 仅1人）
$r = $mkAct(['name' => '空投管理E2E-限量裁切', 'type' => 'condition', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 2, 'total_limit' => 2, 'condition_config' => ['phone_tails' => ['8']]]);
$actC = (int) ($r['data']['id'] ?? 0);
$gen($actC);
$r = $issue($actC);
T('发放：总限量2份→仅1人获发', ($r['code'] ?? -1) === 200 && (int) ($r['data']['issued'] ?? 0) === 1, "issued={$r['data']['issued']} msg={$r['message']}");
$recC = (int) $q("SELECT COUNT(*) FROM nft_airdrop_records WHERE activity_id=$actC");
T('落库：C记录2条（1人×2份）', $recC === 2, "实际 $recC");
$r = $issue($actC);
T('发放：限量耗尽再发被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 6.8 D：库存不足（每人100份 > 剩余池）
$r = $mkAct(['name' => '空投管理E2E-库存不足', 'type' => 'condition', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 100, 'condition_config' => ['phone_tails' => ['7']]]);
$actD = (int) ($r['data']['id'] ?? 0);
$gen($actD);
$r = $issue($actD);
T('发放：库存不足被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
// 6.9 E：hold 型发放
$r = $issue($actE);
T("发放：hold型{$holdN}人×1份", ($r['code'] ?? -1) === 200 && (int) ($r['data']['issued'] ?? 0) === $holdN, "issued={$r['data']['issued']} msg={$r['message']}");
$recE = (int) $q("SELECT COUNT(*) FROM nft_airdrop_records WHERE activity_id=$actE");
T("落库：E记录{$holdN}条", $recE === $holdN, "实际 $recE");
$finalPool = 50 - (2 * $t7 + 2) - 2 - $holdN;
T("库存恒等式：终态池={$finalPool}（50-" . (2 * $t7 + 2) . "-2-{$holdN}）", $poolOf() === $finalPool, '实际 ' . $poolOf());

echo "\n========== 阶段七：编辑/删除约束 ==========\n";
$r = $mkAct(['id' => $actA, 'name' => '空投管理E2E-尾号7', 'type' => 'condition', 'status' => 'active', 'collectible_id' => $holdCid, 'quantity_per_user' => 2, 'condition_config' => ['phone_tails' => ['7']]]);
T('编辑：已发放活动换藏品被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['id' => $actA, 'name' => '空投管理E2E-尾号7', 'type' => 'direct', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 2]);
T('编辑：已发放活动换类型被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = http('POST', '/admin/marketing/airdrop-delete', ['id' => $actA], $adminToken);
T('删除：已发放活动被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['id' => $actA, 'name' => '空投管理E2E-尾号7改', 'type' => 'condition', 'status' => 'paused', 'collectible_id' => $airCid, 'quantity_per_user' => 2, 'condition_config' => ['phone_tails' => ['7']]]);
T('编辑：改名+暂停成功', ($r['code'] ?? -1) === 200, "code={$r['code']} msg={$r['message']}");
$r = http('POST', '/admin/marketing/airdrop-delete', ['id' => $actB], $adminToken);
$eligB = (int) $q("SELECT COUNT(*) FROM nft_airdrop_eligibilities WHERE activity_id=$actB");
T('删除：草稿活动B删除成功', ($r['code'] ?? -1) === 200, "code={$r['code']} msg={$r['message']}");
T('删除：B资格名单已清除', $eligB === 0, "残留 $eligB 条");
foreach ([['D 库存不足', $actD], ['F 全量直投', $actF], ['G 签到型', $actG]] as [$nm, $id]) {
    $r = http('POST', '/admin/marketing/airdrop-delete', ['id' => $id], $adminToken);
    T("删除：未发放活动{$nm}删除", ($r['code'] ?? -1) === 200, "code={$r['code']}");
}

echo "\n========== 阶段八：C端可见性 ==========\n";
[$cid2, $ccode2, $cerr2] = getCaptcha();
$ulogin = http('POST', '/api/auth/login', ['phone' => '13977770007', 'password' => 'Pass#2026', 'captcha_id' => $cid2, 'captcha_code' => $ccode2]);
$userToken = $ulogin['data']['token'] ?? '';
T('C端登录（尾七A）', ($ulogin['code'] ?? -1) === 0 && $userToken, $ulogin['message'] ?? '');
if ($userToken) {
    $inbox = http('GET', '/api/inbox/pending', null, $userToken);
    $hit = 0;
    foreach (($inbox['data']['list'] ?? []) as $it) {
        if ($it['type'] === 'airdrop' && strpos((string) ($it['extra']['reason'] ?? ''), '活动空投') !== false) $hit++;
    }
    T('C端收件箱：尾七A活动空投通知×1（仅首轮；次轮不重复发放）', $hit === 1, "实际 $hit 条");
    $mine = http('GET', '/api/user/collections?page=1&pageSize=100', null, $userToken);
    $holdQty = 0;
    foreach (($mine['data'] ?? []) as $it) if ((int) ($it['id'] ?? 0) === $airCid) $holdQty = (int) ($it['qty'] ?? 0);
    T('C端持仓：尾七A空投藏品×2（首轮2份）', $holdQty === 2, "实际 $holdQty 份");
}
$tail7DHold = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone='13977770037' AND uc.collectible_id=$airCid");
T('落库：尾七D（次轮新增）获空投2份', $tail7DHold === 2, "实际 $tail7DHold 份");
$tail8AId2 = (int) $q("SELECT id FROM nft_users WHERE phone='13988880008'");
$tail8AHold = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$tail8AId2 AND collectible_id=$airCid");
T("落库：尾八A获活动空投" . ($holdN === 1 ? 3 : 1) . "份（hold活动1份" . ($holdN === 1 ? '+限量活动2份' : '') . '）', $tail8AHold === ($holdN === 1 ? 3 : 1), "实际 $tail8AHold 份");

echo "\n========== 阶段九：独立空投回归（藏品详情页入口） ==========\n";
$r = http('POST', '/admin/collectibles/airdrop', ['id' => $airCid, 'users' => ['13988880018'], 'quantity' => 1, 'reason' => '回归测试'], $adminToken);
T('回归：指定用户独立空投正常', ($r['code'] ?? -1) === 200 && (int) ($r['data']['total'] ?? 0) === 1, "code={$r['code']} msg={$r['message']}");

echo "\n========== 阶段十：审计日志 ==========\n";
$saveCnt  = (int) $q("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='marketing' AND action='airdrop_save'");
$eligLog  = (int) $q("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='marketing' AND action='airdrop_eligibility'");
$issueLog = (int) $q("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='marketing' AND action='airdrop_issue'");
$delLog   = (int) $q("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='marketing' AND action='airdrop_delete'");
T('审计：airdrop_save ≥8', $saveCnt >= 8, "实际 $saveCnt");
T('审计：airdrop_eligibility ≥7', $eligLog >= 7, "实际 $eligLog");
T('审计：airdrop_issue ≥4（A两轮+C限量+E hold）', $issueLog >= 4, "实际 $issueLog");
T('审计：airdrop_delete ≥4', $delLog >= 4, "实际 $delLog");

echo "\n==========================================\n";
printf("总计：%d PASS / %d FAIL（%.1fs）\n", $pass, $fail, microtime(true) - $T0);
if ($defects) { echo "问题清单：\n"; foreach ($defects as $d) echo "  - $d\n"; exit(1); }
exit(0);

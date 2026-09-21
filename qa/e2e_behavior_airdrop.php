<?php
/** 行为型空投 E2E 验证（checkin 累计签到 / register 注册行为 / login 登录行为 / invite 邀请行为）
 *  前置：后端 127.0.0.1:8080、MySQL sinan_nft（sinan/sinan123456）
 *  造数：签到甲(3天)/签到乙(1天)/未签到丙/邀请丁(2成功)/高频登录戊(5次)/低频登录己(1次)/窗口内注册庚/老用户辛
 *  期望值动态计算（与后端同一 SQL 口径），兼容库内既有用户行为数据
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

$PDO = new PDO('mysql:host=127.0.0.1;dbname=sinan_nft', 'sinan', 'sinan123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$PDO->exec("SET NAMES utf8mb4");
$q = fn($s) => $PDO->query($s)->fetch(PDO::FETCH_NUM)[0] ?? null;

echo "========== 阶段一：管理端登录 ==========\n";
$T0 = microtime(true);
[$cid, $ccode, $cerr] = getCaptcha();
T('管理端图形验证码获取+破解', $cid && $ccode, $cerr ?: '');
$login = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123', 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$adminToken = $login['data']['token'] ?? '';
T('管理端登录', ($login['code'] ?? -1) === 200 && $adminToken, $login['message'] ?? '');

echo "\n========== 阶段二：测试造数（行为分布可控） ==========\n";
$now = date('Y-m-d H:i:s');
$regWinStart = date('Y-m-d H:i:s', strtotime('-30 minutes')); // register 活动窗口起点
$pwd = password_hash('Pass#2026', PASSWORD_BCRYPT);

// 清理历史 E2E 数据（顺序规避外键 RESTRICT）
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_airdrop_activities a ON a.id=r.activity_id WHERE a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE e FROM nft_airdrop_eligibilities e JOIN nft_airdrop_activities a ON a.id=e.activity_id WHERE a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_airdrop_activities a ON a.id=t.target_id WHERE t.target_type=3 AND a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE FROM nft_airdrop_activities WHERE name LIKE '行为空投E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_collectibles c ON c.id=t.target_id WHERE t.target_type IN (1,2) AND c.name='行为空投E2E藏品'");
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_collectibles c ON c.id=r.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_collectibles c ON c.id=uc.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE i FROM nft_inbox i JOIN nft_collectibles c ON c.id=i.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE FROM nft_collectibles WHERE name='行为空投E2E藏品'");
foreach (["'1360000%'"] as $like) {
    $PDO->exec("DELETE ir FROM nft_invite_records ir JOIN nft_users u ON u.id=ir.inviter_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE cir FROM nft_check_in_records cir JOIN nft_users u ON u.id=cir.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_users u ON u.id=r.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE i FROM nft_inbox i JOIN nft_users u ON u.id=i.user_id WHERE u.phone LIKE $like");
    $PDO->exec("DELETE FROM nft_users WHERE phone LIKE $like");
}

// 8 个测试用户：手机段 1360000XXXX（签到甲/签到乙/未签到丙/邀请丁/登录戊/登录己/注册庚/老用户辛）
$seedUsers = [
    // phone, username, is_realname, is_blacklisted, deleted, created_at, login_count
    ['13600000001', '签到甲', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 1],
    ['13600000002', '签到乙', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 1],
    ['13600000003', '未签到丙', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 1],
    ['13600000004', '邀请丁', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 1],
    ['13600000005', '登录戊', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 5],
    ['13600000006', '登录己', 1, 0, null, date('Y-m-d H:i:s', strtotime('-3 day')), 1],
    ['13600000007', '注册庚', 1, 0, null, date('Y-m-d H:i:s', strtotime('-10 minutes')), 1],
    ['13600000008', '老用户辛', 1, 0, null, date('Y-m-d H:i:s', strtotime('-1 year')), 1],
];
$st = $PDO->prepare("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,realname_status,password,status,is_blacklisted,deleted_at,created_at,updated_at,login_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($seedUsers as $i => $u) {
    $st->execute([$u[0], $u[1], '', 'B' . str_pad((string)($i + 1), 5, '0', STR_PAD_LEFT), 'BC' . $i . 'X', $u[2], $u[2], $pwd, 1, $u[3], $u[4], $u[5], $u[5], $u[6]]);
}
$uid = fn($p) => (int) $q("SELECT id FROM nft_users WHERE phone='$p'");

// 签到记录：甲 3 天（今天/昨天/前天）、乙 1 天（今天）
$d0 = date('Y-m-d'); $d1 = date('Y-m-d', strtotime('-1 day')); $d2 = date('Y-m-d', strtotime('-2 day'));
$stCk = $PDO->prepare("INSERT INTO nft_check_in_records (user_id,activity_id,check_in_date,consecutive_days,reward_type,created_at) VALUES (?,0,?,1,'none',NOW(3))");
foreach ([$d0, $d1, $d2] as $d) $stCk->execute([$uid('13600000001'), $d]);
$stCk->execute([$uid('13600000002'), $d0]);

// 邀请记录：丁 邀请 甲、乙（status=registered，被邀请人唯一）
$stInv = $PDO->prepare("INSERT INTO nft_invite_records (inviter_id,invitee_id,invite_code,status,created_at) VALUES (?,?,'E2EBHV','registered',NOW(3))");
$stInv->execute([$uid('13600000004'), $uid('13600000001')]);
$stInv->execute([$uid('13600000004'), $uid('13600000002')]);

// 动态期望值（与后端同一 SQL 口径；库内既有用户行为数据会计入）
$exp = fn($s) => (int) $q($s);
$valid = 'deleted_at IS NULL AND is_blacklisted=0';
$nCheckin1 = $exp("SELECT COUNT(*) FROM nft_users u WHERE u.$valid AND (SELECT COUNT(DISTINCT cir.check_in_date) FROM nft_check_in_records cir WHERE cir.user_id=u.id) >= 1");
$nCheckin3 = $exp("SELECT COUNT(*) FROM nft_users u WHERE u.$valid AND (SELECT COUNT(DISTINCT cir.check_in_date) FROM nft_check_in_records cir WHERE cir.user_id=u.id) >= 3");
$nLogin3   = $exp("SELECT COUNT(*) FROM nft_users u WHERE u.$valid AND u.login_count >= 3");
$nInvite1  = $exp("SELECT COUNT(*) FROM nft_users u WHERE u.$valid AND (SELECT COUNT(*) FROM nft_invite_records ir WHERE ir.inviter_id=u.id AND ir.status='registered') >= 1");
$nRegWin   = $exp("SELECT COUNT(*) FROM nft_users u WHERE u.$valid AND u.created_at >= '$regWinStart'");
T('造数校验：累计签到≥1天 ≥2人（甲乙+既有）', $nCheckin1 >= 2, "实际 $nCheckin1");
T('造数校验：累计签到≥3天 ≥1人（甲+既有）', $nCheckin3 >= 1, "实际 $nCheckin3");
T('造数校验：累计登录≥3次 ≥1人（戊+既有）', $nLogin3 >= 1, "实际 $nLogin3");
T('造数校验：成功邀请≥1人 ≥1人（丁+既有）', $nInvite1 >= 1, "实际 $nInvite1");
T('造数校验：窗口内注册 ≥1人（庚+既有）', $nRegWin >= 1, "实际 $nRegWin");

echo "\n========== 阶段三：创建空投藏品 ==========\n";
$catId = (int) $q("SELECT id FROM nft_categories LIMIT 1");
$r = http('POST', '/admin/collectibles', [
    'name' => '行为空投E2E藏品', 'category_id' => $catId, 'image' => '/images/collections/cover-1.jpg',
    'price' => 10, 'edition' => 200, 'per_user_limit' => 10, 'issuer' => 'E2E', 'description' => '行为空投验证',
], $adminToken);
$airCid = (int) ($r['data']['id'] ?? 0);
T('后台创建空投藏品', ($r['code'] ?? -1) === 200 && $airCid > 0, $r['message'] ?? '');
$r = http('POST', "/admin/collectibles/$airCid/release", ['status' => 'onsale'], $adminToken);
T('后台上架（库存池=200）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

echo "\n========== 阶段四：行为型活动创建校验 ==========\n";
$mkAct = fn(array $b) => http('POST', '/admin/marketing/airdrop', $b, $adminToken);
$r = $mkAct(['name' => '行为空投E2E-签到0天', 'type' => 'checkin', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['days' => 0]]);
T('校验：签到天数0被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$r = $mkAct(['name' => '行为空投E2E-邀请0人', 'type' => 'invite', 'status' => 'draft', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['count' => 0]]);
T('校验：邀请阈值0被拒（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");

// 四类行为型活动各建一个（active）
$r = $mkAct(['name' => '行为空投E2E-签到1天', 'type' => 'checkin', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['days' => 1]]);
T('创建：checkin 活动（≥1天）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
$r = $mkAct(['name' => '行为空投E2E-登录3次', 'type' => 'login', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['count' => 3]]);
T('创建：login 活动（≥3次）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
$r = $mkAct(['name' => '行为空投E2E-邀请1人', 'type' => 'invite', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'condition_config' => ['count' => 1]]);
T('创建：invite 活动（≥1人）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
$r = $mkAct(['name' => '行为空投E2E-期间注册', 'type' => 'register', 'status' => 'active', 'collectible_id' => $airCid, 'quantity_per_user' => 1, 'start_time' => $regWinStart, 'end_time' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
T('创建：register 活动（起止窗口）', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

// 列表定位活动 ID
$lst = http('GET', '/admin/marketing/airdrop' . qs(['page' => 1, 'pageSize' => 50, 'keyword' => '行为空投E2E']), null, $adminToken);
$rows = $lst['data']['list'] ?? [];
$actId = fn($nm) => (int) ($q("SELECT id FROM nft_airdrop_activities WHERE name='$nm'") ?: 0);
T('列表：5个活动可见（含2个校验失败未建+4成功→实际4）', count($rows) >= 4, 'count=' . count($rows));
$actCheckin  = $actId('行为空投E2E-签到1天');
$actLogin    = $actId('行为空投E2E-登录3次');
$actInvite   = $actId('行为空投E2E-邀请1人');
$actRegister = $actId('行为空投E2E-期间注册');
$findRow = fn($id) => array_values(array_filter($rows, fn($r) => (int) $r['id'] === $id))[0] ?? null;
$rowCheckin = $findRow($actCheckin);
T('列表：checkin 活动阈值回显（days=1）', $rowCheckin && ($rowCheckin['conditionConfig']['days'] ?? 0) === 1, json_encode($rowCheckin['conditionConfig'] ?? null, JSON_UNESCAPED_UNICODE));
$rowInvite = $findRow($actInvite);
T('列表：invite 活动阈值回显（count=1）', $rowInvite && ($rowInvite['conditionConfig']['count'] ?? 0) === 1, json_encode($rowInvite['conditionConfig'] ?? null, JSON_UNESCAPED_UNICODE));

echo "\n========== 阶段五：生成名单（四种行为型） ==========\n";
$gen = fn($id) => http('POST', '/admin/marketing/airdrop/eligibility-generate', ['activity_id' => $id], $adminToken);

$r = $gen($actCheckin);
T('checkin≥1天：名单生成成功', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
T('checkin≥1天：人数=动态期望', (int) ($r['data']['generated'] ?? -1) === $nCheckin1, "got={$r['data']['generated']} want=$nCheckin1");
$inList = fn($id, $uid) => (int) $q("SELECT COUNT(*) FROM nft_airdrop_eligibilities WHERE activity_id=$id AND user_id=$uid AND status='eligible'") === 1;
T('checkin≥1天：签到甲/乙 在名单', $inList($actCheckin, $uid('13600000001')) && $inList($actCheckin, $uid('13600000002')));
T('checkin≥1天：未签到丙 不在名单', !$inList($actCheckin, $uid('13600000003')));

$r = $gen($actLogin);
T('login≥3次：名单生成成功', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
T('login≥3次：人数=动态期望', (int) ($r['data']['generated'] ?? -1) === $nLogin3, "got={$r['data']['generated']} want=$nLogin3");
T('login≥3次：登录戊在名单、登录己不在', $inList($actLogin, $uid('13600000005')) && !$inList($actLogin, $uid('13600000006')));

$r = $gen($actInvite);
T('invite≥1人：名单生成成功', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
T('invite≥1人：人数=动态期望', (int) ($r['data']['generated'] ?? -1) === $nInvite1, "got={$r['data']['generated']} want=$nInvite1");
T('invite≥1人：邀请丁在名单、签到甲不在', $inList($actInvite, $uid('13600000004')) && !$inList($actInvite, $uid('13600000001')));

$r = $gen($actRegister);
T('register 窗口：名单生成成功', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
T('register 窗口：人数=动态期望', (int) ($r['data']['generated'] ?? -1) === $nRegWin, "got={$r['data']['generated']} want=$nRegWin");
T('register 窗口：注册庚在名单、老用户辛不在', $inList($actRegister, $uid('13600000007')) && !$inList($actRegister, $uid('13600000008')));

echo "\n========== 阶段六：checkin 活动完整发放链路 ==========\n";
$issuedBefore = (int) $q("SELECT issued_count FROM nft_airdrop_activities WHERE id=$actCheckin");
$r = http('POST', '/admin/marketing/airdrop/issue', ['activity_id' => $actCheckin], $adminToken);
T('批量发放：checkin≥1天 全部发出', ($r['code'] ?? -1) === 200 && (int) ($r['data']['issued'] ?? 0) === $nCheckin1, $r['message'] ?? "issued={$r['data']['issued']}");

// 到账核对：签到甲
$uidA = $uid('13600000001');
$got = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=$airCid AND source='airdrop'");
T('到账：签到甲获得 1 份持仓', $got === 1, "got=$got");
$inbox = (int) $q("SELECT COUNT(*) FROM nft_inbox WHERE user_id=$uidA AND type='airdrop'");
T('到账：签到甲收件箱通知 1 条', $inbox >= 1, "inbox=$inbox");
$eligIssued = (int) $q("SELECT COUNT(*) FROM nft_airdrop_eligibilities WHERE activity_id=$actCheckin AND user_id=$uidA AND status='issued'");
T('名单：签到甲资格记录=issued', $eligIssued === 1, "rows=$eligIssued");
$recCnt = (int) $q("SELECT COUNT(*) FROM nft_airdrop_records r JOIN nft_airdrop_activities a ON a.id=r.activity_id WHERE a.id=$actCheckin AND r.status='issued'");
T('发放记录：checkin 活动 issued 记录数=人数', $recCnt === $nCheckin1, "records=$recCnt");

// 库存恒等式
$c = $PDO->query("SELECT edition,sold,locked_quantity,reserved_count,airdropped_count,destroyed_count,circulate FROM nft_collectibles WHERE id=$airCid")->fetch(PDO::FETCH_ASSOC);
$pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity'] - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
T('库存恒等式：池=200-发放数', $pool === 200 - $nCheckin1, "pool=$pool airdropped={$c['airdropped_count']}");
T('流通量：circulate=已发份数', (int) $c['circulate'] === $nCheckin1, "circulate={$c['circulate']}");
$actRow = $PDO->query("SELECT issued_count FROM nft_airdrop_activities WHERE id=$actCheckin")->fetch(PDO::FETCH_NUM);
T('活动 issued_count=人数', (int) $actRow[0] === $nCheckin1, "issued={$actRow[0]}");

// 重新生成名单：保留已发放、不重复发
$r = $gen($actCheckin);
T('重生成：成功且新增0（全部已发放）', ($r['code'] ?? -1) === 200 && (int) ($r['data']['generated'] ?? -1) === 0 && (int) ($r['data']['kept_issued'] ?? -1) === $nCheckin1, "gen={$r['data']['generated']} kept={$r['data']['kept_issued']}");
$r = http('POST', '/admin/marketing/airdrop/issue', ['activity_id' => $actCheckin], $adminToken);
T('二次发放：无待发放（4220）', ($r['code'] ?? -1) === 4220, "code={$r['code']} msg={$r['message']}");
$got2 = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=$airCid AND source='airdrop'");
T('到账不重复：签到甲仍 1 份', $got2 === 1, "got=$got2");

echo "\n========== 阶段七：编辑保留阈值 + 名单查看 ==========\n";
// 编辑不传 condition_config → 阈值保留
$r = http('POST', '/admin/marketing/airdrop', ['id' => $actInvite, 'name' => '行为空投E2E-邀请1人', 'type' => 'invite', 'status' => 'paused', 'collectible_id' => $airCid, 'quantity_per_user' => 1], $adminToken);
T('编辑：invite 活动未传 condition_config 成功', ($r['code'] ?? -1) === 200, $r['message'] ?? '');
$cfgJson = (string) $q("SELECT condition_config FROM nft_airdrop_activities WHERE id=$actInvite");
$cfgArr = json_decode($cfgJson, true) ?: [];
T('编辑：阈值保留（count=1 未被清空）', (int) ($cfgArr['count'] ?? 0) === 1, $cfgJson);

// 名单查看接口（行为型）
$r = http('GET', '/admin/marketing/airdrop/eligibilities' . qs(['activity_id' => $actLogin, 'page' => 1, 'pageSize' => 20]), null, $adminToken);
$eligUsers = array_column($r['data']['list'] ?? [], 'userId');
T('名单查看：login 活动名单含登录戊', in_array($uid('13600000005'), array_map('intval', $eligUsers), true), 'users=' . implode(',', $eligUsers));

echo "\n========== 阶段八：审计与清理 ==========\n";
$auditN = (int) $q("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='marketing' AND action IN ('airdrop_save','airdrop_eligibility','airdrop_issue') AND target_id IN ($actCheckin,$actLogin,$actInvite,$actRegister)");
T('审计：save/eligibility/issue 全量记录', $auditN >= 9, "logs=$auditN");

echo "\n========== 清理测试数据 ==========\n";
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_airdrop_activities a ON a.id=r.activity_id WHERE a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE e FROM nft_airdrop_eligibilities e JOIN nft_airdrop_activities a ON a.id=e.activity_id WHERE a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_airdrop_activities a ON a.id=t.target_id WHERE t.target_type=3 AND a.name LIKE '行为空投E2E%'");
$PDO->exec("DELETE FROM nft_airdrop_activities WHERE name LIKE '行为空投E2E%'");
$PDO->exec("DELETE t FROM nft_airdrop_tasks t JOIN nft_collectibles c ON c.id=t.target_id WHERE t.target_type IN (1,2) AND c.name='行为空投E2E藏品'");
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_collectibles c ON c.id=r.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_collectibles c ON c.id=uc.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE i FROM nft_inbox i JOIN nft_collectibles c ON c.id=i.collectible_id WHERE c.name='行为空投E2E藏品'");
$PDO->exec("DELETE FROM nft_collectibles WHERE name='行为空投E2E藏品'");
$PDO->exec("DELETE ir FROM nft_invite_records ir JOIN nft_users u ON u.id=ir.inviter_id WHERE u.phone LIKE '1360000%'");
$PDO->exec("DELETE cir FROM nft_check_in_records cir JOIN nft_users u ON u.id=cir.user_id WHERE u.phone LIKE '1360000%'");
$PDO->exec("DELETE r FROM nft_airdrop_records r JOIN nft_users u ON u.id=r.user_id WHERE u.phone LIKE '1360000%'");
$PDO->exec("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '1360000%'");
$PDO->exec("DELETE i FROM nft_inbox i JOIN nft_users u ON u.id=i.user_id WHERE u.phone LIKE '1360000%'");
$PDO->exec("DELETE FROM nft_users WHERE phone LIKE '1360000%'");
echo "已清理\n";

printf("\n========== 结果：%d PASS / %d FAIL，耗时 %.1fs ==========\n", $pass, $fail, microtime(true) - $T0);
if ($defects) { echo "缺陷清单：\n"; foreach ($defects as $d) echo "  - $d\n"; exit(1); }
exit(0);

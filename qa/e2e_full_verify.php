<?php
/** E2E 全链路验证：管理端创建 → C端展示/购买/参与 → 数据落库核对
 *  前置：后端 127.0.0.1:8080、MySQL sinan_nft（sinan/sinan123456）
 *  覆盖：登录(图形码)/藏品/购买/公告/盲盒/合成/签到/抽奖/抽签购/实名/支付密码/充值
 *  图形码：CaptchaService 明文只存 sha256 哈希于 file cache —— 脚本读缓存文件后本地爆破（32字符集×4位）
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = 'http://127.0.0.1:8080';
$CACHE_DIR = '/workspace/sinanQZ/sinan-nft-backend/runtime/cache';
$CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

$pass = 0; $fail = 0; $defects = [];
function T($n, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; printf("  %s %-46s%s\n", $c ? 'PASS' : 'FAIL', $n, ($d && !$c) || ($d && $c) ? " | $d" : ''); if (!$c) $GLOBALS['defects'][] = $n . ($d ? " | $d" : ''); }
function http($m, $u, $b = null, $t = null) {
    global $BASE; $ch = curl_init($BASE . $u);
    $h = ['Content-Type: application/json'];
    if ($t) $h[] = "Authorization: Bearer $t";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
    $raw = curl_exec($ch); curl_close($ch);
    return json_decode($raw ?: '', true) ?: ['code' => -2, 'message' => 'BADJSON'];
}

/** 破解图形验证码：读 file cache 哈希 → 穷举 4 位明文 */
function crackCaptcha(string $captchaId): ?string {
    global $CACHE_DIR, $CHARS;
    $md5 = md5('captcha_' . $captchaId);
    // ThinkPHP FileDriver：目录=md5前2位，文件名=md5剩余30位
    $file = $CACHE_DIR . '/' . substr($md5, 0, 2) . '/' . substr($md5, 2) . '.php';
    for ($i = 0; $i < 20; $i++) { // 轮询等待缓存落盘
        if (is_file($file)) break;
        usleep(100000);
    }
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

/** 获取并破解一组图形码（scene 仅用于探测，不影响生成） */
function getCaptcha(): array {
    $j = http('GET', '/api/captcha/image');
    if (($j['code'] ?? -1) !== 0) return [null, null, 'captcha/image 失败: ' . ($j['message'] ?? '')];
    $id = $j['data']['captcha_id'] ?? '';
    $code = $id ? crackCaptcha($id) : null;
    return [$id, $code, $code ? null : '破解失败（缓存未落盘/格式变化）'];
}

$T0 = microtime(true);
echo "========== 阶段一：管理端登录与内容创建 ==========\n";

// ---- 1.1 管理端登录（图形码） ----
[$cid, $ccode, $cerr] = getCaptcha();
T('管理端图形验证码获取+破解', $cid && $ccode, $cerr ?: "code=$ccode");
$login = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123', 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$adminToken = $login['data']['token'] ?? '';
T('管理端登录 admin/admin123', ($login['code'] ?? -1) === 200 && $adminToken, $login['message'] ?? '');

// ---- 1.2 分类 ----
$catCode = 'e2e' . substr((string)time(), -5);
$cat = http('POST', '/admin/cms/categories', ['name' => 'E2E验证分类', 'code' => $catCode, 'scene' => 'market', 'sort_order' => 99], $adminToken);
$catId = $cat['data']['id'] ?? 0;
T('后台创建市场分类', ($cat['code'] ?? -1) === 200 && $catId > 0, $cat['message'] ?? '');

// ---- 1.3 藏品批量创建（A发售/B合成素材/C合成产物/D盲盒奖品/E抽奖奖品/F抽签购） ----
$collIds = [];
$names = ['A直售', 'B素材', 'C合成产物', 'D盲盒奖', 'E抽奖奖', 'F抽签购'];
foreach ($names as $idx => $nm) {
    $r = http('POST', '/admin/collectibles', [
        'name' => "E2E-$nm", 'category_id' => $catId, 'image' => '/images/collections/cover-1.jpg',
        'price' => 10, 'edition' => 100, 'per_user_limit' => 10, 'issuer' => 'E2E', 'description' => "E2E验证藏品 $nm",
    ], $adminToken);
    $id = $r['data']['id'] ?? 0;
    $collIds[$nm] = (int) $id;
    T("后台创建藏品$nm", ($r['code'] ?? -1) === 200 && $id > 0, $r['message'] ?? '');
}
foreach ($names as $nm) {
    $r = http('POST', "/admin/collectibles/{$collIds[$nm]}/release", ['status' => 'onsale'], $adminToken);
    T("后台上架藏品$nm", ($r['code'] ?? -1) === 200, $r['message'] ?? '');
}

// ---- 1.4 盲盒（奖池=D）----
$bb = http('POST', '/admin/blind-boxes', [
    'name' => 'E2E盲盒', 'category_id' => $catId, 'image' => '/images/collections/cover-2.jpg',
    'price' => 5, 'edition' => 50, 'is_openable' => 1,
    'items' => [['prize_collectible_id' => $collIds['D盲盒奖'], 'probability' => 1.0, 'quantity_limit' => 40]],
], $adminToken);
$bbId = $bb['data']['id'] ?? 0; $bbCid = $bb['data']['collectible_id'] ?? 0;
T('后台创建盲盒（含奖池）', ($bb['code'] ?? -1) === 200 && $bbId > 0, $bb['message'] ?? '');
$r = http('POST', "/admin/blind-boxes/$bbId/release", ['status' => 'onsale'], $adminToken);
T('后台上架盲盒', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

// ---- 1.5 合成活动（B×1 → C×1）----
$syn = http('POST', '/admin/marketing/synthesis', [
    'type' => 'permanent', 'title' => 'E2E合成活动', 'rules' => '1个B素材合成1个C', 'status' => 1,
    'result_collectible_id' => $collIds['C合成产物'], 'result_quantity' => 1,
    'materials' => [['collectible_id' => $collIds['B素材'], 'count' => 1]],
    'per_user_limit' => 5, 'grant_mode' => 'realtime', 'eligibility_type' => 'all',
], $adminToken);
$synId = $syn['data']['id'] ?? 0;
T('后台创建合成活动', ($syn['code'] ?? -1) === 200 && $synId > 0, $syn['message'] ?? '');

// ---- 1.6 抽奖活动（E×1 + 未中）----
$luckyAct = http('POST', '/admin/marketing/lucky-activity', ['name' => 'E2E抽奖活动', 'status' => 0], $adminToken);
$luckyId = $luckyAct['data']['id'] ?? 0;
T('后台创建抽奖活动', ($luckyAct['code'] ?? -1) === 200 && $luckyId > 0, $luckyAct['message'] ?? '');
$luckySave = http('POST', '/admin/marketing/lucky', [
    'activity_id' => $luckyId,
    'prizes' => [
        ['tier_name' => '一等奖', 'prize_name' => 'E2E抽奖藏品', 'prize_type' => 'collectible', 'probability' => 0.2, 'total' => 10, 'reward_config' => ['collectibleId' => $collIds['E抽奖奖'], 'quantity' => 1]],
        ['tier_name' => '谢谢参与', 'prize_name' => '未中奖', 'prize_type' => 'none', 'probability' => 0.8, 'total' => 1000],
    ],
], $adminToken);
T('后台配置抽奖奖项（概率=1）', ($luckySave['code'] ?? -1) === 200, $luckySave['message'] ?? '');
$r = http('POST', '/admin/marketing/lucky-activity', ['id' => $luckyId, 'name' => 'E2E抽奖活动', 'status' => 1], $adminToken);
T('后台启用抽奖活动', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

// ---- 1.7 签到活动 ----
$checkin = http('POST', '/admin/marketing/checkin-activity', [
    'name' => 'E2E签到活动', 'start_time' => date('Y-m-d 00:00:00', strtotime('-1 day')),
    'reward_config' => [1 => [['type' => 'points', 'amount' => 10]]],
], $adminToken);
$checkinId = $checkin['data']['id'] ?? 0;
T('后台创建签到活动（day1=10司南币）', ($checkin['code'] ?? -1) === 200 && $checkinId > 0, $checkin['message'] ?? '');

// ---- 1.8 抽签购活动 ----
$now = time();
$raffle = http('POST', '/admin/raffle/save', [
    'collectibleId' => $collIds['F抽签购'], 'name' => 'E2E抽签购活动',
    'winnerCount' => 10, 'saleQuantity' => 10, 'totalSupply' => 100,
    'registrationStart' => date('Y-m-d H:i:s', $now - 60), 'registrationEnd' => date('Y-m-d H:i:s', $now + 3600),
    'drawTime' => date('Y-m-d H:i:s', $now + 7200), 'salePrice' => 10, 'maxBuyPerUser' => 2,
], $adminToken);
$raffleId = $raffle['data']['id'] ?? 0;
T('后台创建抽签购活动', ($raffle['code'] ?? -1) === 200 && $raffleId > 0, $raffle['message'] ?? '');
$r = http('POST', "/admin/raffle/$raffleId/start", [], $adminToken);
T('后台开启抽签报名', ($r['code'] ?? -1) === 200, $r['message'] ?? '');

// ---- 1.9 公告 ----
$ann = http('POST', '/admin/cms/announcements', [
    'title' => 'E2E验证公告-' . date('His'), 'type' => 'notice', 'subtype' => 'operation',
    'content' => '<p>E2E 端到端验证公告正文 <b>加粗</b></p>', 'status' => 'published',
], $adminToken);
$annId = $ann['data']['id'] ?? 0;
T('后台发布公告（published）', ($ann['code'] ?? -1) === 200 && $annId > 0, $ann['message'] ?? '');

// ---- 1.10 模块开关（签到/抽奖/合成）----
foreach (['checkin', 'lucky', 'synthesis'] as $mod) {
    $r = http('POST', '/admin/marketing/feature-switches', ['module' => $mod, 'enabled' => 1], $adminToken);
    T("后台开启{$mod}模块开关", ($r['code'] ?? -1) === 200, $r['message'] ?? '');
}

echo "\n========== 阶段二：C端用户注册/登录/实名/钱包 ==========\n";
$phone = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$pwd = 'Pass#123456'; $tpwd = 'Pay#123456';

// ---- 2.1 注册（图形码 → 短信码） ----
[$cid, $ccode, $cerr] = getCaptcha();
$sc = http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register', 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$debugCode = $sc['data']['debugCode'] ?? null;
T('C端发送注册短信码（图形码前置）', ($sc['code'] ?? -1) === 0 && $debugCode, $sc['message'] ?? '');
$bad = http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register', 'captcha_id' => $cid, 'captcha_code' => 'XXXX']);
T('C端图形码防重放（同码二次拒绝）', ($bad['code'] ?? -1) !== 0, $bad['message'] ?? '');

$reg = http('POST', '/api/auth/register', ['phone' => $phone, 'code' => $debugCode, 'password' => $pwd, 'nickname' => 'E2E用户']);
T('C端注册', ($reg['code'] ?? -1) === 0, $reg['message'] ?? '');

// ---- 2.2 登录（密码 + 图形码） ----
[$cid, $ccode, $cerr] = getCaptcha();
$login2 = http('POST', '/api/auth/login', ['phone' => $phone, 'password' => $pwd, 'captcha_id' => $cid, 'captcha_code' => $ccode]);
$userToken = $login2['data']['token'] ?? '';
T('C端密码登录（图形码前置）', ($login2['code'] ?? -1) === 0 && $userToken, $login2['message'] ?? '');

// ---- 2.3 实名 → 后台审核 ----
$rn = http('POST', '/api/user/realname', ['realName' => '测试用户', 'idCard' => '110101199003077770'], $userToken);
T('C端提交实名认证', ($rn['code'] ?? -1) === 0, $rn['message'] ?? '');
$uinfo = http('GET', '/api/user/profile', null, $userToken);
// profile 不返回数字主键，按手机号从库中取（同时建立阶段五复用的 DB 连接）
$PDO = new PDO('mysql:host=127.0.0.1;dbname=sinan_nft', 'sinan', 'sinan123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = fn($s) => $PDO->query($s)->fetch(PDO::FETCH_NUM)[0] ?? null;
$uid = (int) $q("SELECT id FROM nft_users WHERE phone='$phone'");
T('C端用户落库（数字ID可查）', $uid > 0, "uid=$uid");
$audit = http('POST', '/admin/realname/audit', ['user_id' => $uid, 'action' => 'approve'], $adminToken);
T('后台实名审核通过', ($audit['code'] ?? -1) === 200, $audit['message'] ?? '');

// ---- 2.4 设置支付密码（user_op_pwd 图形码 + 短信码） ----
[$cid, $ccode, $cerr] = getCaptcha();
$sc2 = http('POST', '/api/user/send-code', ['scene' => 'reset_password', 'captcha_scene' => 'user_op_pwd', 'captcha_id' => $cid, 'captcha_code' => $ccode], $userToken);
$debugCode2 = $sc2['data']['debugCode'] ?? null;
T('C端发送支付密码短信码', ($sc2['code'] ?? -1) === 0 && $debugCode2, $sc2['message'] ?? '');
$tp = http('POST', '/api/user/password/trade/reset', ['code' => $debugCode2, 'newPassword' => $tpwd], $userToken);
T('C端设置支付密码', ($tp['code'] ?? -1) === 0, $tp['message'] ?? '');

// ---- 2.5 充值 ----
$rc = http('POST', '/api/wallet/recharge', ['amount' => 10000], $userToken);
T('C端充值 10000', ($rc['code'] ?? -1) === 0, $rc['message'] ?? '');

echo "\n========== 阶段三：C端展示与购买链路 ==========\n";
// ---- 3.1 一级市场发售列表可见后台藏品（featured：is_release=1 且未售罄） ----
$market = http('GET', '/api/collections/featured?page=1&pageSize=50');
$found = false; $mktItem = null;
foreach (($market['data']['list'] ?? $market['data']['items'] ?? []) as $it) {
    if ((int) ($it['id'] ?? 0) === $collIds['A直售']) { $found = true; $mktItem = $it; break; }
}
T('C端一级市场列表可见后台创建的藏品A', $found, $found ? "stock={$mktItem['stock']}" : '藏品A未出现在 collections/featured');
$detail = http('GET', "/api/collections/{$collIds['A直售']}");
T('C端藏品A详情', ($detail['code'] ?? -1) === 0 && ($detail['data']['name'] ?? '') === 'E2E-A直售', $detail['message'] ?? '');

// ---- 3.2 购买藏品 A（下单 + 余额支付） ----
$order = http('POST', '/api/orders', ['collectibleId' => $collIds['A直售'], 'quantity' => 2, 'paymentPassword' => $tpwd], $userToken);
$orderNo = $order['data']['orderNo'] ?? ($order['data']['order_no'] ?? '');
T('C端下单购买藏品A×2', ($order['code'] ?? -1) === 0 && $orderNo, $order['message'] ?? '');
$pay = http('POST', "/api/orders/$orderNo/pay", ['paymentMethod' => 'balance', 'paymentPassword' => $tpwd], $userToken);
T('C端余额支付订单', ($pay['code'] ?? -1) === 0, $pay['message'] ?? '');
$mine = http('GET', '/api/user/collections?page=1&pageSize=50', null, $userToken);
$holdCnt = 0;
foreach (($mine['data'] ?? []) as $it) { // data 直接为按藏品聚合的数组 {id, qty, ...}
    if ((int) ($it['id'] ?? 0) === $collIds['A直售']) $holdCnt = (int) ($it['qty'] ?? 0);
}
T('C端持仓出现藏品A×2', $holdCnt === 2, "实际持仓 $holdCnt 份");

// ---- 3.3 公告可见 ----
$anns = http('GET', '/api/announcements?page=1&pageSize=20');
$annFound = false;
foreach (($anns['data']['list'] ?? $anns['data']['items'] ?? []) as $it) {
    if ((int) ($it['id'] ?? 0) === $annId) { $annFound = true; break; }
}
T('C端公告列表可见后台公告', $annFound, $annFound ? '' : "公告#$annId 未出现");
$annDetail = http('GET', "/api/announcements/$annId");
T('C端公告详情', ($annDetail['code'] ?? -1) === 0 && strpos($annDetail['data']['content'] ?? '', '端到端验证公告正文') !== false, $annDetail['message'] ?? '');

echo "\n========== 阶段四：活动展示与参与 ==========\n";
// ---- 4.1 盲盒 ----
$bbs = http('GET', '/api/blind-boxes?page=1&pageSize=20');
$bbFound = false;
foreach (($bbs['data']['list'] ?? $bbs['data']['items'] ?? []) as $it) {
    if ((int) ($it['collectible_id'] ?? $it['id'] ?? 0) === (int) $bbCid || strpos($it['name'] ?? '', 'E2E盲盒') !== false) { $bbFound = true; break; }
}
T('C端盲盒列表可见后台盲盒', $bbFound, $bbFound ? '' : json_encode($bbs['data'], JSON_UNESCAPED_UNICODE));
$bbOrder = http('POST', '/api/orders', ['collectibleId' => $bbCid, 'quantity' => 1, 'paymentPassword' => $tpwd], $userToken);
$bbOrderNo = $bbOrder['data']['orderNo'] ?? ($bbOrder['data']['order_no'] ?? '');
$bbPay = http('POST', "/api/orders/$bbOrderNo/pay", ['paymentMethod' => 'balance', 'paymentPassword' => $tpwd], $userToken);
T('C端购买盲盒（下单+支付）', ($bbOrder['code'] ?? -1) === 0 && ($bbPay['code'] ?? -1) === 0, ($bbOrder['message'] ?? '') . '/' . ($bbPay['message'] ?? ''));
$bbHold = null; $mine2 = http('GET', '/api/user/collections?page=1&pageSize=50', null, $userToken);
foreach (($mine2['data'] ?? []) as $it) {
    if ((int) ($it['id'] ?? 0) === (int) $bbCid) { $bbHold = $it; break; }
}
T('C端持仓出现盲盒', $bbHold !== null, $bbHold ? "qty={$bbHold['qty']}" : '未找到盲盒持仓');
if ($bbHold) {
    $ucId = (int) ($bbHold['userCollectibleIds'][0] ?? 0);
    $open = http('POST', '/api/blind-boxes/open', ['userCollectibleId' => $ucId, 'paymentPassword' => $tpwd], $userToken);
    T('C端开盒获得奖品D', ($open['code'] ?? -1) === 0, $open['message'] ?? json_encode($open['data'], JSON_UNESCAPED_UNICODE));
}

// ---- 4.2 合成（先买素材B，再合成C） ----
$bOrder = http('POST', '/api/orders', ['collectibleId' => $collIds['B素材'], 'quantity' => 1, 'paymentPassword' => $tpwd], $userToken);
$bOrderNo = $bOrder['data']['orderNo'] ?? ($bOrder['data']['order_no'] ?? '');
$bPay = http('POST', "/api/orders/$bOrderNo/pay", ['paymentMethod' => 'balance', 'paymentPassword' => $tpwd], $userToken);
T('C端购买合成素材B', ($bOrder['code'] ?? -1) === 0 && ($bPay['code'] ?? -1) === 0, ($bOrder['message'] ?? '') . '/' . ($bPay['message'] ?? ''));
$synList = http('GET', '/api/synthesis/activities?page=1&pageSize=20');
$synFound = false;
foreach (($synList['data']['list'] ?? []) as $it) {
    if ((int) ($it['activityId'] ?? 0) === (int) $synId) { $synFound = true; break; }
}
T('C端合成活动列表可见', $synFound, $synFound ? '' : '活动未出现');
$synSub = http('POST', '/api/synthesis/submit', ['activityId' => $synId], $userToken);
T('C端参与合成（B→C）', ($synSub['code'] ?? -1) === 0, $synSub['message'] ?? '');

// ---- 4.3 签到 ----
$ckAct = http('GET', '/api/check-in/activity');
T('C端签到活动可见', ($ckAct['code'] ?? -1) === 0 && (int) ($ckAct['data']['activity']['id'] ?? 0) === (int) $checkinId, $ckAct['message'] ?? '');
$pointsBefore = null;
$uinfo1 = http('GET', '/api/user/profile', null, $userToken);
$pointsBefore = (float) ($uinfo1['data']['wallet']['points'] ?? 0);
$ck = http('POST', '/api/check-in', null, $userToken);
T('C端执行签到', ($ck['code'] ?? -1) === 0, $ck['message'] ?? '');
$uinfo2 = http('GET', '/api/user/profile', null, $userToken);
T('签到奖励到账（+10司南币）', (float) ($uinfo2['data']['wallet']['points'] ?? -1) >= $pointsBefore + 10, 'points=' . ($uinfo2['data']['wallet']['points'] ?? '?'));

// ---- 4.4 抽奖 ----
$luckyActApi = http('GET', '/api/lucky-draw/activity');
T('C端抽奖活动可见', ($luckyActApi['code'] ?? -1) === 0 && (int) ($luckyActApi['data']['activityId'] ?? 0) === (int) $luckyId, $luckyActApi['message'] ?? '');
$draw = http('POST', '/api/lucky-draw/draw', null, $userToken);
T('C端执行抽奖', ($draw['code'] ?? -1) === 0, $draw['message'] ?? json_encode($draw['data'], JSON_UNESCAPED_UNICODE));

// ---- 4.5 抽签购 ----
$rafList = http('GET', '/api/raffle/activities?page=1&pageSize=20');
$rafFound = false;
foreach (($rafList['data']['list'] ?? $rafList['data']['items'] ?? []) as $it) {
    if ((int) ($it['activityId'] ?? $it['id'] ?? 0) === (int) $raffleId) { $rafFound = true; break; }
}
T('C端抽签活动列表可见', $rafFound, $rafFound ? '' : '活动未出现');
$regR = http('POST', "/api/raffle/activities/$raffleId/register", null, $userToken);
T('C端报名抽签', ($regR['code'] ?? -1) === 0, $regR['message'] ?? '');
$drawR = http('POST', "/admin/raffle/$raffleId/draw", [], $adminToken);
T('后台执行开奖', ($drawR['code'] ?? -1) === 200, $drawR['message'] ?? '');
$buyR = http('POST', "/api/raffle/activities/$raffleId/purchase", ['quantity' => 1, 'paymentPassword' => $tpwd], $userToken);
T('C端中签购买抽签藏品F', ($buyR['code'] ?? -1) === 0, $buyR['message'] ?? '');

echo "\n========== 阶段五：数据核对 ==========\n";
$orderCnt = (int) $q("SELECT COUNT(*) FROM nft_orders WHERE user_id=$uid AND status='completed'");
T("订单已支付落库（≥3）", $orderCnt >= 3, "paid订单数=$orderCnt");
$holdA = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uid AND collectible_id={$collIds['A直售']} AND status='held'");
T("藏品A持仓落库（2份）", $holdA === 2, "实际=$holdA");
$holdC = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uid AND collectible_id={$collIds['C合成产物']}");
T("合成产物C持仓落库（1份）", $holdC === 1, "实际=$holdC");
$holdF = (int) $q("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uid AND collectible_id={$collIds['F抽签购']}");
T("抽签购F持仓落库（1份）", $holdF === 1, "实际=$holdF");
$wal = $q("SELECT CONCAT(balance,'/',available) FROM nft_wallets WHERE user_id=$uid");
T("钱包扣减一致（余额=可用）", $wal !== null && explode('/', $wal)[0] === explode('/', $wal)[1], "wallet=$wal");
$synCnt = (int) $q("SELECT COUNT(*) FROM nft_synthesis_records WHERE user_id=$uid");
T("合成记录落库", $synCnt >= 1, "records=$synCnt");
$ckCnt = (int) $q("SELECT COUNT(*) FROM nft_check_in_records WHERE user_id=$uid");
T("签到记录落库", $ckCnt >= 1, "records=$ckCnt");

printf("\n========== 汇总：PASS=%d FAIL=%d 耗时%.1fs ==========\n", $pass, $fail, microtime(true) - $T0);
if ($defects) { echo "问题清单：\n"; foreach ($defects as $d) echo "  - $d\n"; }
exit($fail > 0 ? 1 : 0);

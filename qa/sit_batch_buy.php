<?php
/** 批量购买（POST /api/resale/batch-buy）专项回归：
 *   1) 地板价扫描：按价格升序锁定 quantity 份、totalPrice=各挂单价之和、池中 lockedCount 与之一致
 *   2) 取消批量单 → batch_listing_ids 内全部挂单恢复 selling
 *   3) 并发双批量 → 锁定集合无交集、无重复售卖
 *   4) 支付批量单 → 逐份过户 + 卖家结算(actual_amount) + 买家扣款 + 移出池/进成交动态
 *   5) 限度钳制（quantity>limit 静默降为 limit；可售不足限度按实际份数）
 *   6) 开关关闭 / 指定用户白名单边界
 *   7) 余额不足 4003 且挂单保持锁定、取消后释放
 *  自清洁：手机号段 139000093xx、藏品 9612、batch_buy_* 配置先存后还原，可重复执行
 */
date_default_timezone_set('Asia/Shanghai');
$BASE  = getenv('QA_BASE')  ?: 'http://127.0.0.1:8080';
$BASE2 = getenv('QA_BASE2') ?: 'http://127.0.0.1:8081';
define('CID', 9612);
define('PHONE_LIKE', '139000093%');
define('TRADE_PWD', 'Trade#2026');
define('FEE_RATE_KEY', 'resale_fee_rate');
require __DIR__ . '/bootstrap_db.php';

$pass = 0; $fail = 0;
function T($n, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? "  PASS " : "  FAIL ") . $n . ($d ? " | $d" : "") . "\n"; }

function req($base, $m, $u, $b = null, $t = null) {
  $h = ['Content-Type: application/json']; if ($t) $h[] = "Authorization: Bearer $t";
  $ch = curl_init($base . $u);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
  $raw = curl_exec($ch); $err = curl_error($ch);
  unset($ch); // 不调用 curl_close（PHP 8.5 已废弃），交由脚本结束时回收
  $j = json_decode($raw, true);
  return $j ?: ['code' => -2, 'message' => $err !== '' ? "CURLEXC:$err" : 'BADJSON'];
}
function http($m, $u, $b = null, $t = null) { global $BASE; return req($BASE, $m, $u, $b, $t); }

/** 同一时刻并发发起多个请求（真实争抢挂单行锁） */
function multi(array $jobs) {
  $mh = curl_multi_init(); $chs = [];
  foreach ($jobs as $i => $j) {
    $h = ['Content-Type: application/json']; if (!empty($j['token'])) $h[] = "Authorization: Bearer {$j['token']}";
    $ch = curl_init($j['base'] . $j['path']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $j['method'] ?? 'POST', CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($j['body'] ?? [])]);
    curl_multi_add_handle($mh, $ch); $chs[$i] = $ch;
  }
  $deadline = microtime(true) + 40;
  do {
    $running = 0; $status = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh, 0.05);
    if (microtime(true) > $deadline) break;
  } while ($running && $status === CURLM_OK);
  $out = [];
  foreach ($chs as $i => $ch) {
    $raw = curl_multi_getcontent($ch); curl_multi_remove_handle($mh, $ch); unset($ch);
    $out[$i] = json_decode($raw, true) ?: ['code' => -2, 'message' => 'BADJSON'];
  }
  return $out;
}

function v($sql) { global $PDO; $r = $PDO->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function rows($sql) { global $PDO; return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function exe($sql) { global $PDO; return $PDO->exec($sql); }
function setCfg($k, $val) { global $PDO; $s = $PDO->prepare('UPDATE nft_system_configs SET config_value=? WHERE config_key=?'); $s->execute([(string) $val, $k]); }
function getCfg($k) { global $PDO; return (string) $PDO->query("SELECT config_value FROM nft_system_configs WHERE config_key='" . addslashes($k) . "'")->fetchColumn(); }

function regUser($phone, $nick) { global $PDO, $BASE;
  exe("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','" . password_hash('654321', PASSWORD_BCRYPT) . "','" . date('Y-m-d H:i:s', time() + 600) . "',NOW(),'127.0.0.1',NOW())");
  $j = http('POST', '/api/auth/register', ['phone' => $phone, 'code' => '654321', 'nickname' => $nick, 'password' => 'Pass#999']);
  $uid = ($j['code'] ?? -1) === 0 ? (int)v("SELECT id FROM nft_users WHERE phone='$phone'") : 0;
  return [$uid, (string)($j['data']['token'] ?? '')];
}
function seedWallet($uid, $amount) { global $PDO;
  exe("DELETE FROM nft_wallets WHERE user_id=$uid");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points,created_at,updated_at) VALUES ($uid,$amount,$amount,0,0,NOW(),NOW())");
  exe("INSERT INTO nft_wallet_transactions (user_id,trans_type,title,direction,amount,balance_after,created_at) VALUES ($uid,'recharge','批量购买回归开账',1,$amount,$amount,NOW())");
}
function seedHolder($uid) { global $PDO;
  $s = 'SN-' . CID . '-B' . substr((string)microtime(true), -6) . rand(100, 999);
  exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at) VALUES ($uid," . CID . ",'$s','purchase',0,NOW(),'held',NOW(),NOW())");
  return (int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
}
/** 池中该藏品挂单：[listingId => ['locked'=>bool,'price'=>float]] + lockedCount + floorPrice */
function pool() { $r = http('GET', '/api/resale/listings?collectibleId=' . CID . '&page=1&pageSize=100');
  $m = []; foreach (($r['data']['list'] ?? []) as $it) { $m[(int)$it['listingId']] = ['locked' => !empty($it['locked']), 'price' => (float)($it['price'] ?? 0)]; }
  return [$m, (int)($r['data']['lockedCount'] ?? -1), (float)($r['data']['floorPrice'] ?? 0), (int)($r['data']['ordersCount'] ?? -1)];
}
/** 接口 floorPrice/ordersCount 为全站口径（仅 selling，不含锁定中）→ 与 DB 直接对账 */
function dbSellingFloor() { global $PDO; $x = $PDO->query("SELECT MIN(price) FROM nft_resale_listings WHERE status='selling'")->fetchColumn(); return $x === null ? 0.0 : (float)$x; }
function dbSellingCount() { global $PDO; return (int)$PDO->query("SELECT COUNT(*) FROM nft_resale_listings WHERE status='selling'")->fetchColumn(); }
/** 本藏品池内可购（未锁定）最低价 —— 前端展示的实际地板价 */
function unlockedFloor(array $m) { $p = array_column(array_filter($m, fn($x) => !$x['locked']), 'price'); return $p === [] ? null : (float)min($p); }
function priceOf($lid) { global $PDO; return (float)$PDO->query("SELECT price FROM nft_resale_listings WHERE id=" . (int)$lid)->fetchColumn(); }
function inHistory($lid) { $r = http('GET', '/api/resale/history?collectibleId=' . CID . '&page=1&pageSize=100');
  foreach (($r['data']['list'] ?? $r['data'] ?? []) as $it) { if ((int)($it['id'] ?? 0) === $lid) return true; } return false; }
function batchIds($orderNo) { $s = (string)v("SELECT batch_listing_ids FROM nft_orders WHERE order_no='$orderNo'");
  return array_values(array_filter(array_map('intval', explode(',', $s)))); }
function idsOf($arr) { sort($arr); return $arr; }
function purgePhones() { global $PDO;
  $ids = array_map('intval', $PDO->query("SELECT id FROM nft_users WHERE phone LIKE '" . PHONE_LIKE . "'")->fetchAll(PDO::FETCH_COLUMN) ?: []);
  if (!$ids) return 0;
  $in = implode(',', $ids);
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach (['nft_wallets', 'nft_wallet_transactions', 'nft_user_collectibles', 'nft_orders', 'nft_payments', 'nft_buy_requests'] as $tb) exe("DELETE FROM $tb WHERE user_id IN ($in)");
  exe("DELETE FROM nft_transfers WHERE from_user_id IN ($in) OR to_user_id IN ($in)");
  exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($in)");
  exe("DELETE FROM nft_users WHERE id IN ($in)");
  exe("SET FOREIGN_KEY_CHECKS=1");
  return count($ids);
}

echo "========== 0. 造数与批量配置 ==========\n";
$origCfg = [];
foreach (['batch_buy_enabled', 'batch_buy_scope', 'batch_buy_limit', 'batch_buy_users'] as $k) $origCfg[$k] = getCfg($k);
echo "原配置：" . json_encode($origCfg, JSON_UNESCAPED_UNICODE) . "\n";

$purged = purgePhones();
// 上一轮可能残留 9612 及其订单（外键 RESTRICT）→ 造数前置清理统一关闭外键检查
exe("SET FOREIGN_KEY_CHECKS=0");
exe("DELETE FROM nft_orders WHERE collectible_id=" . CID);
exe("DELETE FROM nft_user_collectibles WHERE collectible_id=" . CID);
exe("DELETE FROM nft_resale_listings WHERE collectible_id=" . CID);
exe("DELETE FROM nft_collectibles WHERE id=" . CID);
exe("SET FOREIGN_KEY_CHECKS=1");
// 造数前基线快照 → 收尾校验「清理干净且无副作用」
$snap = [];
foreach (['nft_users' => 'users', 'nft_collectibles' => 'collectibles', 'nft_resale_listings' => 'listings',
          'nft_orders' => 'orders', 'nft_wallets' => 'wallets', 'nft_wallet_transactions' => 'walletTx',
          'nft_user_collectibles' => 'userCollectibles', 'nft_transfers' => 'transfers', 'nft_payments' => 'payments'] as $tb => $k) {
  $snap[$k] = (int)v("SELECT COUNT(*) FROM $tb");
}
echo "基线快照：" . json_encode($snap) . "\n";
exe("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at)
  VALUES (" . CID . ",1,'批量购买回归藏品','','',100,1000,0,0,0,10,1,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW())");
setCfg('batch_buy_enabled', '1'); setCfg('batch_buy_scope', 'all'); setCfg('batch_buy_limit', '5'); setCfg('batch_buy_users', '');

[$uidA, $tokA] = regUser('13900009301', '批量卖家甲');
[$uidB, $tokB] = regUser('13900009302', '批量买家乙');
[$uidC, $tokC] = regUser('13900009303', '批量买家丙');
[$uidD, $tokD] = regUser('13900009304', '余额不足丁');
$th = password_hash(TRADE_PWD, PASSWORD_BCRYPT);
foreach ([$uidA, $uidB, $uidC, $uidD] as $u) exe("UPDATE nft_users SET is_realname=1, realname_status=2, transaction_password='$th' WHERE id=$u");
foreach ([$uidA, $uidB, $uidC] as $u) seedWallet($u, 100000);
seedWallet($uidD, 5);
T('0.1 四个测试用户就绪', $uidA && $uidB && $uidC && $uidD && $tokA && $tokB && $tokC && $tokD, "A=$uidA B=$uidB C=$uidC D=$uidD 清理旧数据=$purged");

$prices = [10, 20, 30, 40, 50];
$lidOf = [];   // price => listingId
$ucOf  = [];   // listingId => user_collectible_id
$ok = true;
foreach ($prices as $p) {
  $uc = seedHolder($uidA);
  $r  = http('POST', '/api/resale/listings', ['userCollectibleId' => $uc, 'price' => $p, 'paymentPassword' => TRADE_PWD], $tokA);
  $lid = (int)($r['data']['listingId'] ?? $r['data']['id'] ?? 0);
  if (($r['code'] ?? -1) !== 0 || $lid <= 0) { $ok = false; echo "  挂单 $p 失败：" . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; continue; }
  $lidOf[$p] = $lid; $ucOf[$lid] = $uc;
}
T('0.2 A 挂出 5 份（10/20/30/40/50）', $ok && count($lidOf) === 5, json_encode($lidOf));
[$m, $lc, $floor, $cnt] = pool();
T('0.3 初始池：5 份可售、无锁定、本藏品可购最低价 10', count($m) === 5 && $lc === 0 && unlockedFloor($m) === 10.0,
  'n=' . count($m) . " lockedCount=$lc floor=" . var_export(unlockedFloor($m), true));
T('0.4 接口 floorPrice/ordersCount 与 DB selling 口径对账', abs($floor - dbSellingFloor()) < 0.001 && $cnt === dbSellingCount(),
  "apiFloor=$floor dbFloor=" . dbSellingFloor() . " apiCount=$cnt dbCount=" . dbSellingCount());

echo "\n========== 1. 地板价扫描与批量锁定 ==========\n";
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 3], $tokB);
$noB1 = (string)($r['data']['orderNo'] ?? '');
T('1.1 B 批量下单 qty=3 成功', ($r['code'] ?? -1) === 0 && $noB1 !== '', json_encode($r, JSON_UNESCAPED_UNICODE));
T('1.2 返回 quantity=3 / floorPrice=10 / totalPrice=60',
  (int)($r['data']['quantity'] ?? 0) === 3 && abs((float)($r['data']['floorPrice'] ?? 0) - 10) < 0.001 && abs((float)($r['data']['totalPrice'] ?? 0) - 60) < 0.001,
  "q={$r['data']['quantity']} floor={$r['data']['floorPrice']} total={$r['data']['totalPrice']}");
$ids = idsOf(batchIds($noB1));
$expect3 = [$lidOf[10], $lidOf[20], $lidOf[30]]; sort($expect3);
T('1.3 锁定集合为最便宜的 3 份（价格升序）', $ids === $expect3, 'ids=' . json_encode($ids) . ' expect=' . json_encode($expect3));
T('1.4 订单 quantity/total_price 与 DB 一致',
  (int)v("SELECT quantity FROM nft_orders WHERE order_no='$noB1'") === 3 && abs((float)v("SELECT total_price FROM nft_orders WHERE order_no='$noB1'") - 60) < 0.001);
T('1.5 订单 source=market 且 resale_listing_id 为首份',
  v("SELECT source FROM nft_orders WHERE order_no='$noB1'") === 'market' && (int)v("SELECT resale_listing_id FROM nft_orders WHERE order_no='$noB1'") === $lidOf[10]);
[$m, $lc, $floor] = pool();
$lockedIds = array_keys(array_filter($m, fn($x) => $x['locked'])); sort($lockedIds);
T('1.6 池中锁定 3 份（lockedCount=3，集合一致）', $lc === 3 && $lockedIds === $expect3, "lockedCount=$lc locked=" . json_encode($lockedIds));
T('1.7 未锁定的 2 份仍可售，本藏品可购最低价升至 40', count($m) === 5 && empty($m[$lidOf[40]]['locked']) && unlockedFloor($m) === 40.0,
  'unlockedFloor=' . var_export(unlockedFloor($m), true));
T('1.7b 接口 floorPrice 不计入锁定挂单（等于全站 selling 最低价 40）', abs($floor - 40.0) < 0.001 && abs($floor - dbSellingFloor()) < 0.001, "apiFloor=$floor dbFloor=" . dbSellingFloor());
foreach ($expect3 as $lid) T("1.8 DB 挂单 $lid 状态 sold（锁定语义）", v("SELECT status FROM nft_resale_listings WHERE id=$lid") === 'sold');
$r = http('GET', '/api/resale/batch-buy/config', null, $tokB);
T('1.9 batch-buy/config 返回 enabled=true limit=5', ($r['code'] ?? -1) === 0 && ($r['data']['enabled'] ?? false) === true && (int)($r['data']['limit'] ?? 0) === 5, json_encode($r['data'] ?? []));
// 业务规则：B 手上这笔批量单未付款前，不得再次批量锁定
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokB);
T('1.9b 有待支付订单时再次批量下单被拒 3005', ($r['code'] ?? 0) === 3005, "code={$r['code']} msg={$r['message']}");
// 卖家本人批量购买应因排除自己而无单可买
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokA);
T('1.10 A 自购被排除（1002 暂无可购买的挂单）', ($r['code'] ?? 0) === 1002, "code={$r['code']} msg={$r['message']}");

echo "\n========== 2. 取消批量单 → 全部释放 ==========\n";
$r = http('POST', "/api/orders/$noB1/cancel", null, $tokB);
T('2.1 B 取消批量单成功', ($r['code'] ?? -1) === 0, "code={$r['code']} msg={$r['message']}");
[$m, $lc, $floor] = pool();
$anyLocked = count(array_filter($m, fn($x) => $x['locked'])) > 0;
T('2.2 batch_listing_ids 内 3 份全部恢复可售（无残留锁定）', $lc === 0 && !$anyLocked && unlockedFloor($m) === 10.0, "lockedCount=$lc unlockedFloor=" . var_export(unlockedFloor($m), true));
$sold = (int)v("SELECT COUNT(*) FROM nft_resale_listings WHERE collectible_id=" . CID . " AND status='sold'");
T('2.3 DB 中该藏品无 sold 挂单', $sold === 0, "sold=$sold");

echo "\n========== 3. 并发双批量 → 挂单不重复售出 ==========\n";
$rr = multi([
  ['base' => $BASE,  'path' => '/api/resale/batch-buy', 'token' => $tokB, 'body' => ['collectibleId' => CID, 'quantity' => 3]],
  ['base' => $BASE2, 'path' => '/api/resale/batch-buy', 'token' => $tokC, 'body' => ['collectibleId' => CID, 'quantity' => 3]],
]);
$noB2 = (string)($rr[0]['data']['orderNo'] ?? ''); $noC2 = (string)($rr[1]['data']['orderNo'] ?? '');
T('3.1 B/C 并发批量下单均成功', ($rr[0]['code'] ?? -1) === 0 && ($rr[1]['code'] ?? -1) === 0 && $noB2 !== '' && $noC2 !== '',
  json_encode(array_map(fn($x) => ['code' => $x['code'] ?? null, 'q' => $x['data']['quantity'] ?? null, 'msg' => $x['message'] ?? null], $rr), JSON_UNESCAPED_UNICODE));
$idsB = idsOf(batchIds($noB2)); $idsC = idsOf(batchIds($noC2));
$qB = count($idsB); $qC = count($idsC);
T('3.2 两份订单 quantity 无重叠（合计 5，集合交集为空）', $qB + $qC === 5 && !array_intersect($idsB, $idsC), "B=$qB(" . json_encode($idsB) . ") C=$qC(" . json_encode($idsC) . ")");
$all5 = array_map('intval', array_values($lidOf)); sort($all5);
$union = $idsB; foreach ($idsC as $x) $union[] = $x; sort($union);
T('3.3 锁定并集恰为全部 5 份挂单', $union === $all5, 'union=' . json_encode($union));
$qs = [$qB, $qC]; sort($qs);
T('3.4 实际份数受可售数量限制（3+2）', $qs === [2, 3], 'quantities=' . json_encode($qs));
$minB = (float)min(array_map('priceOf', $idsB)); $minC = (float)min(array_map('priceOf', $idsC));
T('3.5 各自 floorPrice = 自身集合最低价、totalPrice = 自身集合之和',
  abs((float)($rr[0]['data']['floorPrice'] ?? -1) - $minB) < 0.001 && abs((float)($rr[1]['data']['floorPrice'] ?? -1) - $minC) < 0.001 &&
  abs((float)($rr[0]['data']['totalPrice'] ?? -1) - array_sum(array_map('priceOf', $idsB))) < 0.001 &&
  abs((float)($rr[1]['data']['totalPrice'] ?? -1) - array_sum(array_map('priceOf', $idsC))) < 0.001,
  "floorB={$rr[0]['data']['floorPrice']}/$minB totalB={$rr[0]['data']['totalPrice']} floorC={$rr[1]['data']['floorPrice']}/$minC totalC={$rr[1]['data']['totalPrice']}");
T('3.5b 两份订单 totalPrice 之和 = 全部 5 份挂单价之和（150）',
  abs(((float)$rr[0]['data']['totalPrice'] + (float)$rr[1]['data']['totalPrice']) - 150) < 0.001);
[$m, $lc, $floor, $cnt] = pool();
T('3.5c 池中 5 份全部锁定、可购最低价不存在（全站 selling 已清空）',
  $lc === 5 && count(array_filter($m, fn($x) => !$x['locked'])) === 0 && unlockedFloor($m) === null && abs($floor - dbSellingFloor()) < 0.001 && $cnt === dbSellingCount(),
  "lockedCount=$lc apiFloor=$floor dbFloor=" . dbSellingFloor() . " apiCount=$cnt");

echo "\n========== 4. 支付批量单 → 过户 + 结算 + 移出池 ==========\n";
$payNo = $qB === 3 ? $noB2 : $noC2; $payTok = $qB === 3 ? $tokB : $tokC;
$payUid = $qB === 3 ? $uidB : $uidC; $payIds = $qB === 3 ? $idsB : $idsC;
$otherNo = $qB === 3 ? $noC2 : $noB2; $otherTok = $qB === 3 ? $tokC : $tokB;
$total = (float)v("SELECT total_price FROM nft_orders WHERE order_no='$payNo'");
$inPre = implode(',', $payIds);
$settle = (float)v("SELECT COALESCE(SUM(actual_amount),0) FROM nft_resale_listings WHERE id IN ($inPre)");
$balA0 = (float)v("SELECT balance FROM nft_wallets WHERE user_id=$uidA");
$availBuyer0 = (float)v("SELECT available FROM nft_wallets WHERE user_id=$payUid");
$r = http('POST', "/api/orders/$payNo/pay", ['paymentMethod' => 'balance', 'paymentPassword' => TRADE_PWD], $payTok);
T('4.1 买家支付批量单成功（total=' . $total . '）', ($r['code'] ?? -1) === 0, "code={$r['code']} msg={$r['message']}");
T('4.2 买家余额扣减 totalPrice', abs((float)v("SELECT available FROM nft_wallets WHERE user_id=$payUid") - ($availBuyer0 - $total)) < 0.001,
  'before=' . $availBuyer0 . ' after=' . v("SELECT available FROM nft_wallets WHERE user_id=$payUid"));
$balA1 = (float)v("SELECT balance FROM nft_wallets WHERE user_id=$uidA");
T('4.3 卖家到账 = 各份 actual_amount 之和（扣手续费）', abs($balA1 - ($balA0 + $settle)) < 0.001, "settle=$settle 增量=" . round($balA1 - $balA0, 2));
T('4.4 卖家结算流水 3 条（寄售成交结算）', (int)v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id=$uidA AND title='寄售成交结算' AND biz_no='$payNo'") === count($payIds));
$moved = (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN (SELECT user_collectible_id FROM nft_resale_listings WHERE id IN ($inPre)) AND user_id=$payUid AND status='held'");
T('4.5 全部资产过户给买家且状态 held', $moved === count($payIds), "moved=$moved/" . count($payIds));
foreach ($payIds as $lid) { [$mp] = pool(); T("4.6 挂单 $lid 已成交并从池中消失、出现在成交动态", !isset($mp[$lid]) && inHistory($lid)); }
[$m, $lc] = pool();
T('4.7 剩余 2 份仍在池中且仍锁定（另一订单占用）', $lc === 2 && count($m) === 2, "n=" . count($m) . " lockedCount=$lc");
T('4.8 订单状态 completed', v("SELECT status FROM nft_orders WHERE order_no='$payNo'") === 'completed');

echo "\n========== 5. 取消另一份批量单 → 释放剩余 2 份 ==========\n";
$r = http('POST', "/api/orders/$otherNo/cancel", null, $otherTok);
T('5.1 取消成功', ($r['code'] ?? -1) === 0, "code={$r['code']} msg={$r['message']}");
[$m, $lc, $floor] = pool();
T('5.2 剩余 2 份恢复可售', count($m) === 2 && $lc === 0, "n=" . count($m) . " lockedCount=$lc floor=$floor");

echo "\n========== 6. 限度钳制 ==========\n";
setCfg('batch_buy_limit', '2');
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 5], $tokB);
$noB3 = (string)($r['data']['orderNo'] ?? ''); $idsB3 = idsOf(batchIds($noB3));
T('6.1 limit=2 时请求 5 份 → 静默钳制为 2', ($r['code'] ?? -1) === 0 && (int)$r['data']['quantity'] === 2 && count($idsB3) === 2,
  'q=' . json_encode($r['data']['quantity'] ?? null) . ' ids=' . json_encode($idsB3));
$r = http('POST', "/api/orders/$noB3/cancel", null, $tokB);
T('6.2 取消释放', ($r['code'] ?? -1) === 0);
setCfg('batch_buy_limit', '10');
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 10], $tokB);
$noB4 = (string)($r['data']['orderNo'] ?? '');
T('6.3 limit=10 但仅 2 份可售 → 按实际份数 2 下单', ($r['code'] ?? -1) === 0 && (int)$r['data']['quantity'] === 2, 'q=' . json_encode($r['data']['quantity'] ?? null));
$r = http('POST', "/api/orders/$noB4/cancel", null, $tokB);
T('6.4 取消释放', ($r['code'] ?? -1) === 0);
setCfg('batch_buy_limit', '0');
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokB);
T('6.5 limit=0 视为不可用（1001）', ($r['code'] ?? 0) === 1001, "code={$r['code']} msg={$r['message']}");
setCfg('batch_buy_limit', '5');

echo "\n========== 7. 开关与白名单边界 ==========\n";
setCfg('batch_buy_enabled', '0');
$r = http('GET', '/api/resale/batch-buy/config', null, $tokB);
T('7.1 关闭后 config.enabled=false limit=0', ($r['code'] ?? -1) === 0 && ($r['data']['enabled'] ?? true) === false && (int)($r['data']['limit'] ?? -1) === 0, json_encode($r['data'] ?? []));
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 2], $tokB);
T('7.2 关闭后批量下单被拒 1001「批量购买未开启」', ($r['code'] ?? 0) === 1001 && (string)($r['message'] ?? '') === '批量购买未开启', "code={$r['code']} msg={$r['message']}");
setCfg('batch_buy_enabled', '1'); setCfg('batch_buy_scope', 'specific'); setCfg('batch_buy_users', "13900009301\n13900009302");
$r = http('GET', '/api/resale/batch-buy/config', null, $tokB);
T('7.3 白名单内（B）可用', ($r['data']['enabled'] ?? false) === true && (int)($r['data']['limit'] ?? 0) === 5, json_encode($r['data'] ?? []));
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokC);
T('7.4 白名单外（C）拒绝', ($r['code'] ?? 0) === 1001 && mb_strpos((string)($r['message'] ?? ''), '无批量购买权限') !== false, "code={$r['code']} msg={$r['message']}");
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokB);
$noW = (string)($r['data']['orderNo'] ?? '');
T('7.5 白名单内实际下单成功并释放', ($r['code'] ?? -1) === 0 && http('POST', "/api/orders/$noW/cancel", null, $tokB)['code'] === 0, "orderNo=$noW");
setCfg('batch_buy_scope', 'all'); setCfg('batch_buy_users', '');
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 0], $tokB);
T('7.6 quantity<=0 参数校验 1001', ($r['code'] ?? 0) === 1001, "code={$r['code']} msg={$r['message']}");
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 2]);
T('7.7 未登录批量下单 2001', ($r['code'] ?? 0) === 2001, "code={$r['code']}");

echo "\n========== 8. 余额不足：支付失败但挂单保持锁定 ==========\n";
[$m0, $lc0] = pool();
$floor0 = unlockedFloor($m0);
$r = http('POST', '/api/resale/batch-buy', ['collectibleId' => CID, 'quantity' => 1], $tokD);
$noD = (string)($r['data']['orderNo'] ?? ''); $idsD = batchIds($noD);
T('8.0 下单前无锁定', $lc0 === 0 && $floor0 !== null, "lockedCount=$lc0 floor=" . var_export($floor0, true));
T('8.1 D（余额 5）批量下单成功锁定 1 份，价格取自最低可购挂单', ($r['code'] ?? -1) === 0 && count($idsD) === 1
  && abs((float)($r['data']['floorPrice'] ?? -1) - $floor0) < 0.001 && (float)($r['data']['totalPrice'] ?? -1) === priceOf($idsD[0] ?? 0),
  'ids=' . json_encode($idsD) . ' floor=' . json_encode($r['data']['floorPrice'] ?? null) . ' expect=' . var_export($floor0, true));
$lidD = $idsD[0]; $ucD = (int)v("SELECT user_collectible_id FROM nft_resale_listings WHERE id=$lidD");
[$m, $lc] = pool();
T('8.2 下单后该挂单在池中标记锁定', ($m[$lidD]['locked'] ?? null) === true && $lc === 1, "lockedCount=$lc");
$r = http('POST', "/api/orders/$noD/pay", ['paymentMethod' => 'balance', 'paymentPassword' => TRADE_PWD], $tokD);
T('8.3 余额不足支付被拒 4003', ($r['code'] ?? 0) === 4003, "code={$r['code']} msg={$r['message']}");
T('8.4 失败后资产未过户、挂单仍锁定、订单仍待支付',
  (int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucD") === $uidA &&
  v("SELECT status FROM nft_resale_listings WHERE id=$lidD") === 'sold' &&
  v("SELECT status FROM nft_orders WHERE order_no='$noD'") === 'pending');
$r = http('POST', "/api/orders/$noD/cancel", null, $tokD);
[$m, $lc, $floor] = pool();
T('8.5 取消后释放且可购最低价复原', ($r['code'] ?? -1) === 0 && $lc === 0 && unlockedFloor($m) === $floor0 && abs($floor - dbSellingFloor()) < 0.001,
  "lockedCount=$lc floor=" . var_export(unlockedFloor($m), true) . " expect=" . var_export($floor0, true));

echo "\n========== 9. 清理测试数据 ==========\n";
foreach ($origCfg as $k => $val) setCfg($k, $val);
$nowCfg = []; foreach (['batch_buy_enabled', 'batch_buy_scope', 'batch_buy_limit', 'batch_buy_users'] as $k) $nowCfg[$k] = getCfg($k);
T('9.1 batch_buy_* 配置还原为原值', $nowCfg === $origCfg, json_encode($nowCfg, JSON_UNESCAPED_UNICODE));
$delUsers = purgePhones();
exe("SET FOREIGN_KEY_CHECKS=0");
exe("DELETE FROM nft_payments WHERE order_id IN (SELECT id FROM nft_orders WHERE collectible_id=" . CID . ")");
exe("DELETE FROM nft_orders WHERE collectible_id=" . CID);
exe("DELETE FROM nft_user_collectibles WHERE collectible_id=" . CID);
exe("DELETE FROM nft_resale_listings WHERE collectible_id=" . CID);
exe("DELETE FROM nft_collectibles WHERE id=" . CID);
exe("SET FOREIGN_KEY_CHECKS=1");
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '" . PHONE_LIKE . "'");
$left = [
  'users'   => (int)v("SELECT COUNT(*) FROM nft_users WHERE phone LIKE '" . PHONE_LIKE . "'"),
  'coll'    => (int)v("SELECT COUNT(*) FROM nft_collectibles WHERE id=" . CID),
  'uc'      => (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=" . CID),
  'listing' => (int)v("SELECT COUNT(*) FROM nft_resale_listings WHERE collectible_id=" . CID),
  'orders'  => (int)v("SELECT COUNT(*) FROM nft_orders WHERE collectible_id=" . CID),
  'code'    => (int)v("SELECT COUNT(*) FROM nft_verification_codes WHERE phone LIKE '" . PHONE_LIKE . "'"),
  'wallet'  => (int)v("SELECT COUNT(*) FROM nft_wallets WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '" . PHONE_LIKE . "')"),
];
T('9.2 测试数据零残留', array_sum($left) === 0, json_encode($left) . " 删除用户数=$delUsers");
$snapNow = [];
foreach (['nft_users' => 'users', 'nft_collectibles' => 'collectibles', 'nft_resale_listings' => 'listings',
          'nft_orders' => 'orders', 'nft_wallets' => 'wallets', 'nft_wallet_transactions' => 'walletTx',
          'nft_user_collectibles' => 'userCollectibles', 'nft_transfers' => 'transfers', 'nft_payments' => 'payments'] as $tb => $k) {
  $snapNow[$k] = (int)v("SELECT COUNT(*) FROM $tb");
}
$drift = array_filter($snap, fn($val, $k) => $snapNow[$k] !== $val, ARRAY_FILTER_USE_BOTH);
T('9.3 业务表总行数回到造数前快照（无副作用残留）', $drift === [], 'diff=' . json_encode($drift) . ' now=' . json_encode($snapNow));

echo "\n========== 结果: PASS=$pass FAIL=$fail ==========\n";
exit($fail > 0 ? 1 : 0);

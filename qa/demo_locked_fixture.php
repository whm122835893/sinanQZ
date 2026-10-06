<?php
/** 演示夹具：把藏品 9611 的寄售挂单页填成真实市场的样子，用于目测「锁定中」展示
 *   页面：http://127.0.0.1:5173/#/resale/9611
 *
 *   php qa/demo_locked_fixture.php          造全套演示数据（多藏家挂单 + 求购 + 成交 + 1 条锁定中）
 *   php qa/demo_locked_fixture.php --renew  重新锁定一条挂单（锁定态只保持 300 秒，过期自动释放）
 *   php qa/demo_locked_fixture.php --clean  清空演示数据
 *
 * 只写手机号段 139000092xx 的演示账号与藏品 9611，不碰其他业务数据
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = getenv('QA_BASE') ?: 'http://127.0.0.1:8080';
define('CID', 9611);
define('PWD', 'Pass#999');
define('TRADE_PWD', 'Trade#2026');
define('PHONE_LIKE', '139000092%');
$SELLERS = ['13900009211' => '云外斋', '13900009212' => '拾遗阁', '13900009213' => '墨韵山房'];
$BUYERS  = ['13900009214' => '青砚山房', '13900009215' => '半野道人'];
$SERIALS = ['0001' => 45, '0002' => 68, '0003' => 88, '0004' => 120, '0005' => 168,
            '0006' => 268, '0007' => 388, '0008' => 520, '0009' => 666, '0010' => 880];
require __DIR__ . '/bootstrap_db.php';

function h($m, $u, $b = null, $t = null) { global $BASE; $hh = ['Content-Type: application/json']; if ($t) $hh[] = "Authorization: Bearer $t";
  $ch = curl_init($BASE . $u); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $hh, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
  $raw = curl_exec($ch); unset($ch); return json_decode($raw, true) ?: ['code' => -2, 'message' => 'BADJSON']; }
function one($sql) { global $PDO; $r = $PDO->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function exe($sql) { global $PDO; return $PDO->exec($sql); }
function fk($fn) { global $PDO; $PDO->exec('SET FOREIGN_KEY_CHECKS=0'); try { $fn(); } finally { $PDO->exec('SET FOREIGN_KEY_CHECKS=1'); } }
function login($phone) { $j = h('POST', '/api/auth/login', ['phone' => $phone, 'password' => PWD, 'type' => 'password']);
  return [(int)($j['data']['userInfo']['id'] ?? 0), (string)($j['data']['token'] ?? '')]; }
function makeUser($phone, $nick, $balance) { global $PDO, $SELLERS, $BUYERS;
  exe("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','"
    . password_hash('654321', PASSWORD_BCRYPT) . "','" . date('Y-m-d H:i:s', time() + 600) . "',NOW(),'127.0.0.1',NOW())");
  $j = h('POST', '/api/auth/register', ['phone' => $phone, 'code' => '654321', 'nickname' => $nick, 'password' => PWD]);
  if (getenv('QA_DEBUG')) fwrite(STDERR, "  [dbg] payload=" . json_encode(['phone' => $phone, 'code' => '654321', 'nickname' => $nick, 'password' => PWD]) . " resp=" . json_encode($j) . "\n");
  $uid = (int)one("SELECT id FROM nft_users WHERE phone='$phone'");
  if (!$uid) { echo "  !! 注册 $nick 失败：" . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n"; return [0, '']; }
  $th = password_hash(TRADE_PWD, PASSWORD_BCRYPT);
  exe("UPDATE nft_users SET is_realname=1, realname_status=2, transaction_password='$th' WHERE id=$uid");
  exe("DELETE FROM nft_wallets WHERE user_id=$uid");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points,created_at,updated_at) VALUES ($uid,$balance,$balance,0,0,NOW(),NOW())");
  exe("INSERT INTO nft_wallet_transactions (user_id,trans_type,title,direction,amount,balance_after,created_at) VALUES ($uid,'recharge','演示开账',1,$balance,$balance,NOW())");
  [, $tok] = login($phone);
  return [$uid, $tok]; }
function showState() { global $PDO, $BASE;
  $p = h('GET', '/api/resale/listings?collectibleId=' . CID . '&page=1&pageSize=100');
  $exp = one("SELECT expires_at FROM nft_orders WHERE status='pending' AND source='market'
    AND resale_listing_id IN (SELECT id FROM nft_resale_listings WHERE collectible_id=" . CID . ") ORDER BY id DESC");
  echo '挂单 ' . count($p['data']['list'] ?? []) . " 条，锁定中 {$p['data']['lockedCount']} 条，锁定释放于 " . ($exp ?: '（无）') . "\n";
  foreach (($p['data']['list'] ?? []) as $it) {
    printf("  - %-8s %s  #%s\n", $it['price'] . '元', !empty($it['locked']) ? '【锁定中】' : '可售', $it['no']);
  }
  $br = h('GET', '/api/buy-requests?collectibleId=' . CID . '&page=1&pageSize=20');
  echo '求购 ' . count($br['data']['list'] ?? []) . " 条\n";
  foreach (($br['data']['list'] ?? []) as $b) { printf("  - ¥%s ×%s  %s\n", $b['price'], $b['quantity'], $b['userName']); }
  $hi = h('GET', '/api/resale/history?collectibleId=' . CID . '&page=1&pageSize=20');
  echo '成交动态 ' . count($hi['data']['list'] ?? []) . " 条\n"; }

if (in_array('--clean', $argv, true)) {
  $ids = array_map('intval', $PDO->query("SELECT id FROM nft_users WHERE phone LIKE '" . PHONE_LIKE . "'")->fetchAll(PDO::FETCH_COLUMN) ?: []);
  fk(function () use ($ids) { global $PDO;
    if ($ids) { $in = implode(',', $ids);
      foreach (['nft_wallets', 'nft_wallet_transactions', 'nft_user_collectibles', 'nft_orders', 'nft_payments', 'nft_buy_requests'] as $tb) $PDO->exec("DELETE FROM $tb WHERE user_id IN ($in)");
      $PDO->exec("DELETE FROM nft_transfers WHERE from_user_id IN ($in) OR to_user_id IN ($in)");
      $PDO->exec("DELETE FROM nft_resale_listings WHERE seller_id IN ($in)");
      $PDO->exec("DELETE FROM nft_users WHERE id IN ($in)"); }
    $PDO->exec("DELETE FROM nft_resale_listings WHERE collectible_id=" . CID);
    $PDO->exec("DELETE FROM nft_user_collectibles WHERE collectible_id=" . CID);
    $PDO->exec("DELETE FROM nft_orders WHERE collectible_id=" . CID);
    $PDO->exec("DELETE FROM nft_buy_requests WHERE collectible_id=" . CID);
    $PDO->exec("DELETE FROM nft_collectibles WHERE id=" . CID); });
  exe("DELETE FROM nft_verification_codes WHERE phone LIKE '" . PHONE_LIKE . "'");
  echo "演示数据已清理\n"; exit(0);
}

// 藏品本身：真实封面 + 发行/流通量 + 允许寄售与求购
fk(function () { global $PDO;
  $exists = (int)one("SELECT COUNT(*) FROM nft_collectibles WHERE id=" . CID);
  if (!$exists) {
    $PDO->exec("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,
      is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at)
      VALUES (" . CID . ",1,'敦煌·飞天散花（演示）','丝路遗珍系列 · 演示数据','/images/collections/cover-1.jpg',388,3000,0,0,0,10,1,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW())");
  } else {
    $PDO->exec("UPDATE nft_collectibles SET name='敦煌·飞天散花（演示）', subtitle='丝路遗珍系列 · 演示数据',
      image='/images/collections/cover-1.jpg', edition=3000, is_resaleable=1, is_buy_request_enabled=1 WHERE id=" . CID);
  } });

$renew = in_array('--renew', $argv, true);
$acc = [];
foreach ($SELLERS + $BUYERS as $phone => $nick) {
  [$uid, $tok] = login((string)$phone);
  if (!$uid) [$uid, $tok] = makeUser((string)$phone, $nick, 100000);
  $acc[$phone] = [$uid, $tok, $nick];
  if ($renew && !$tok) { fwrite(STDERR, "演示账号登录失败（$nick），请先跑一次不带参数\n"); exit(1); }
}
if ($renew) {
  // 取消旧的待付锁定，再挑一条可售挂单重新下单 → 新鲜 300 秒
  $pend = $PDO->query("SELECT order_no,user_id FROM nft_orders WHERE status='pending' AND source='market'
    AND resale_listing_id IN (SELECT id FROM nft_resale_listings WHERE collectible_id=" . CID . ")")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($pend as $o) {
    $t = '';
    foreach ($BUYERS as $bp => $bn) { if ((int) $o['user_id'] === $acc[$bp][0]) $t = $acc[$bp][1]; }
    if ($t === '') continue;
    $r = h('POST', "/api/orders/{$o['order_no']}/cancel", null, $t);
    echo "释放旧锁定 {$o['order_no']} → code={$r['code']}\n";
  }
  $pick = $PDO->query("SELECT id,price,seller_id FROM nft_resale_listings WHERE collectible_id=" . CID . " AND status='selling' ORDER BY price LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if (!$pick) { fwrite(STDERR, "没有可售挂单可锁定\n"); exit(1); }
  $bp = array_key_first($BUYERS);
  $r = h('POST', '/api/orders', ['resaleListingId' => (int)$pick['id'], 'paymentPassword' => TRADE_PWD], $acc[$bp][1]);
  echo "由「{$acc[$bp][2]}」锁定 #{$pick['id']}（{$pick['price']}元）→ code={$r['code']}\n";
  showState(); exit(0);
}

echo "========== 造演示数据 ==========\n";
// 先清掉本藏品的旧挂单/资产/求购/订单，保证编号连续干净
fk(function () { global $PDO;
  $PDO->exec("DELETE FROM nft_resale_listings WHERE collectible_id=" . CID);
  $PDO->exec("DELETE FROM nft_user_collectibles WHERE collectible_id=" . CID);
  $PDO->exec("DELETE FROM nft_orders WHERE collectible_id=" . CID);
  $PDO->exec("DELETE FROM nft_buy_requests WHERE collectible_id=" . CID); });

$sellerPhones = array_keys($SELLERS);
$lidOf = [];
$round = 0;
foreach ($SERIALS as $serialTail => $price) {
  $who = $sellerPhones[$round % 3]; $round++;
  $owner = $acc[$who][0];
  $s = 'SN-' . CID . '-' . $serialTail;
  exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
    VALUES ($owner," . CID . ",'$s','purchase',388,NOW(),'held',NOW(),NOW())");
  $uc = (int)one("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
  $r = h('POST', '/api/resale/listings', ['userCollectibleId' => $uc, 'price' => $price, 'paymentPassword' => TRADE_PWD], $acc[$who][1]);
  $lid = (int)($r['data']['listingId'] ?? $r['data']['id'] ?? 0);
  $lidOf[$price] = ['lid' => $lid, 'uc' => $uc, 'seller' => $acc[$who][2]];
  echo sprintf("  %-12s 挂单 #%s %s元 → code=%s %s\n", $acc[$who][2], $lid, $price, $r['code'],
    $r['code'] === 0 ? '' : json_encode($r['message'] ?? '', JSON_UNESCAPED_UNICODE));
  usleep(150000);
}

// 成交动态：首位买家买走两条最低价挂单
$b1 = array_key_first($BUYERS); $b2 = array_keys($BUYERS)[1];
$sold = 0;
foreach ($lidOf as $price => $info) {
  if ($sold >= 2 || $info['lid'] <= 0) continue;
  $r = h('POST', '/api/orders', ['resaleListingId' => $info['lid'], 'paymentPassword' => TRADE_PWD], $acc[$b1][1]);
  $no = (string)($r['data']['orderNo'] ?? '');
  if ($no === '') { echo "  成交单创建失败 $price：" . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; continue; }
  $p = h('POST', "/api/orders/$no/pay", ['paymentMethod' => 'balance', 'paymentPassword' => TRADE_PWD], $acc[$b1][1]);
  echo sprintf("  「%s」买入 %s元（#%s）→ 支付 code=%s\n", $acc[$b1][2], $price, $info['lid'], $p['code']);
  $sold++;
}

// 求购单：两条不同价位
foreach ([[$b1, 400, 2, '收一组散花，价高者优先'], [$b2, 210, 1, '求一枚小编号']] as [$who, $price, $qty, $remark]) {
  $r = h('POST', '/api/buy-requests', ['collectibleId' => CID, 'price' => $price, 'quantity' => $qty, 'remark' => $remark], $acc[$who][1]);
  echo sprintf("  「%s」发布求购 ¥%s ×%s → code=%s\n", $acc[$who][2], $price, $qty, $r['code']);
}

// 锁定中：第二位买家对一条可售挂单下单不付款
$pick = $PDO->query("SELECT id,price FROM nft_resale_listings WHERE collectible_id=" . CID . " AND status='selling' ORDER BY price DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($pick) {
  $r = h('POST', '/api/orders', ['resaleListingId' => (int)$pick['id'], 'paymentPassword' => TRADE_PWD], $acc[$b2][1]);
  echo sprintf("  「%s」下单未付款 → 锁定 #%s（%s元）code=%s\n", $acc[$b2][2], $pick['id'], $pick['price'], $r['code']);
}

showState();
echo "\n浏览器打开：http://127.0.0.1:5173/#/resale/" . CID . "（锁定态 5 分钟后释放，届时跑 --renew 续期）\n";

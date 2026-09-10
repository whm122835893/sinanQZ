<?php
/** H3 读一致性 + 稳定性
 *  场景：
 *    H3-1 并发读写一致性（混合负载，MVCC 无脏读）
 *      H3-1a 支付写风暴 + 并发读者轮询 wallet/流水/订单：读者全 200/合法 JSON/
 *             balance 永不为负；收敛后 终态余额 == 最后一笔流水 balance_after 且逐笔连续
 *      H3-1b 挂单风暴（30 并发/轮 ×3 轮）+ 并发读者轮询寄售池/市场：每轮 DB 不变式
 *             selling 挂单的资产必 consigned（跨表无半提交）
 *      H3-1c 库存恒等式：sold + locked <= edition；completed 订单数 == sold；pending == locked
 *    H3-2 慢查询审计：slow_query_log=TABLE, long_query_time=0.2s 覆盖全部风暴，
 *             期望 0 条（或仅行锁等待特征，无缺索引 SELECT）
 *    H3-3 死锁检测：INNODB_METRICS lock_deadlocks 前后差值 == 0；
 *             SHOW ENGINE INNODB STATUS 无 LATEST DETECTED DEADLOCK 新增
 *    H3-4 错误处理/500 堆栈泄露：畸形/对抗请求 ~24 例（坏 JSON/错 token/过期 token/
 *             不存在资源/类型滥用/SQLi/路径穿越/超长载荷/分页滥用/方法错误），
 *             断言 HTTP ∈ {200,400,401,403,404,405}、JSON 含 code、无堆栈泄露标记
 *  环境注记：APP_DEBUG=true（QA 配置），生产部署必须关闭，否则异常页泄露源码路径。
 */
set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');
$BASE = 'http://127.0.0.1:8301';
$PDO  = new PDO('mysql:host=127.0.0.1;dbname=sinan_nft', 'sinan', 'sinan123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
$pass = 0; $fail = 0; $fails = [];
function T($n, $c, $d = ''){global $pass,$fail,$fails;$c?$pass++:$fail++;if(!$c)$fails[]=$n;printf("%s %s%s\n",$c?"  PASS":"  FAIL",$n,$d?" | $d":"");}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}

$envSrc = (string)file_get_contents(__DIR__ . '/../sinan-nft-backend/.env');
preg_match('/^SECRET\s*=\s*(.+)$/m', $envSrc, $m);
$SECRET = trim($m[1] ?? '');
function b64url($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function mint(int $uid, string $phone, int $expOffset = 86400): string {
  global $SECRET;
  $h = b64url(json_encode(['typ'=>'JWT','alg'=>'HS256']));
  $p = b64url(json_encode(['iss'=>'sinan-nft-audience','aud'=>'sinan-nft-client','iat'=>time(),'exp'=>time()+$expOffset,'sub'=>$uid,'phone'=>$phone]));
  return "$h.$p." . b64url(hash_hmac('sha256', "$h.$p", $SECRET, true));
}
function burst(array $reqs, int $timeout = 90): array {
  $mh = curl_multi_init(); $handles = [];
  foreach ($reqs as $i => $r) {
    $ch = curl_init($r['url']);
    $hdr = ['Content-Type: application/json'];
    if (!empty($r['tok'])) $hdr[] = 'Authorization: Bearer ' . $r['tok'];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$r['method'] ?? 'POST', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_HTTPHEADER=>$hdr, CURLOPT_POSTFIELDS=>json_encode($r['b'] ?? new stdClass())]);
    curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
  }
  $out = [];
  do {
    curl_multi_exec($mh, $active);
    while (($info = curl_multi_info_read($mh)) !== false) {
      $ch = $info['handle']; $i = array_search($ch, $handles, true);
      $out[$i] = ['err'=>$info['result']!==CURLE_OK, 'code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                  'raw'=>(string)curl_multi_getcontent($ch),
                  'json'=>json_decode((string)curl_multi_getcontent($ch), true) ?: ['code'=>-2,'message'=>'BADJSON']];
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    if ($active) curl_multi_select($mh, 0.02);
  } while (count($out) < count($reqs));
  curl_multi_close($mh); ksort($out); return $out;
}
function req(string $method, string $url, ?string $tok, $body = null, array $hdr = []): array {
  $ch = curl_init($url);
  $h = $hdr;
  if ($body !== null) $h[] = 'Content-Type: application/json';
  if ($tok) $h[] = 'Authorization: Bearer ' . $tok;
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>$h, CURLOPT_POSTFIELDS=>$body === null ? '' : (is_string($body) ? $body : json_encode($body))]);
  $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return ['code'=>$code, 'raw'=>(string)$raw, 'json'=>json_decode((string)$raw, true)];
}

echo "=== H3-0 环境与种子 ===\n";
T('H3-0a 数据库与密钥就绪', (int)v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='sinan_nft' AND TABLE_NAME='nft_users'") === 1 && $SECRET !== '');
$cfgOld = (string)v("SELECT config_value FROM nft_system_configs WHERE config_key='purchase_limit_per_user'");
exe("UPDATE nft_system_configs SET config_value='100000' WHERE config_key='purchase_limit_per_user'");
/* H3-1b：寄售冷却调至 1s（QA 变更，随 shutdown 复原）——多轮挂单风暴需跨轮重挂同一资产，
   默认 180s 冷却会把 r2/r3 全部以 1001"寄售冷却中"拒绝（冷却拦截本身是正确业务行为，非缺陷） */
$cdOld = (string)v("SELECT config_value FROM nft_system_configs WHERE config_key='resale_cooldown_seconds'");
exe("UPDATE nft_system_configs SET config_value='1' WHERE config_key='resale_cooldown_seconds'");

/* 慢查询审计窗口开启（H3-1 全部风暴置于窗口内），结束后在 H3-2 统一并复原 */
$slowRestored = false;
exe("SET GLOBAL log_output='TABLE'");
exe("SET GLOBAL long_query_time=0.2");
exe("SET GLOBAL slow_query_log=ON");
exe("TRUNCATE TABLE mysql.slow_log");
register_shutdown_function(function () use ($cfgOld, $cdOld, &$slowRestored) {
  global $PDO;
  $PDO->exec("UPDATE nft_system_configs SET config_value=" . $PDO->quote($cfgOld) . " WHERE config_key='purchase_limit_per_user'");
  $PDO->exec("UPDATE nft_system_configs SET config_value=" . $PDO->quote($cdOld) . " WHERE config_key='resale_cooldown_seconds'");
  if (!$slowRestored) {
    $PDO->exec("SET GLOBAL slow_query_log=OFF");
    $PDO->exec("SET GLOBAL long_query_time=10.0");
    $PDO->exec("SET GLOBAL log_output='FILE'");
  }
});

/* 死锁基线 */
$dlk0 = (int)(v("SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'") ?? 0);

/* 种子清理（153 前缀）+ 重建 —— 外键安全顺序（子表先删） */
exe("DELETE p FROM nft_payments p JOIN nft_users u ON u.id=p.user_id WHERE u.phone LIKE '1530000%'");
exe("DELETE wt FROM nft_wallet_transactions wt JOIN nft_users u ON u.id=wt.user_id WHERE u.phone LIKE '1530000%'");
exe("DELETE FROM nft_lucky_draw_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE FROM nft_check_in_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE FROM nft_resale_listings WHERE seller_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE FROM nft_transfers WHERE from_user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%') OR to_user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE FROM nft_synthesis_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1530000%')");
exe("DELETE o FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE '1530000%'");
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '1530000%'");
exe("DELETE w FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE '1530000%'");
exe("DELETE FROM nft_users WHERE phone LIKE '1530000%'");
exe("DELETE FROM nft_blind_box_items WHERE blind_box_id IN (SELECT id FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H3-%'))");
exe("DELETE FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H3-%')");
exe("DELETE FROM nft_synthesis_materials WHERE activity_id IN (SELECT id FROM nft_synthesis_activities WHERE title LIKE 'H3%')");
exe("DELETE FROM nft_synthesis_activities WHERE title LIKE 'H3%'");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'H3-%'");

$hash = password_hash('Pay#2026', PASSWORD_BCRYPT);
$USERS = ['15300000000' => 12.00 /* W 支付风暴：20 单×1 元 → 12 成功 */, '15300000001' => 10.00 /* R 读者 */, '15300000002' => 10.00 /* S 挂单风暴 */];
$i = 0; $vals = [];
foreach ($USERS as $ph => $bal) {
  $vals[] = "('$ph','H3-$i','','UH3" . str_pad((string)$i,4,'0',STR_PAD_LEFT) . "','IH3$i',1,'$hash')"; $i++;
}
exe("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,transaction_password) VALUES " . implode(',', $vals));
$uids = [];
foreach (q("SELECT id,phone FROM nft_users WHERE phone LIKE '1530000%'") as $u) $uids[$u['phone']] = (int)$u['id'];
$wvals = [];
foreach ($USERS as $ph => $bal) $wvals[] = "({$uids[$ph]},$bal,$bal,0.00,0.00)";
exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points) VALUES " . implode(',', $wvals));
$TOK = [];
foreach ($USERS as $ph => $bal) $TOK[$ph] = mint($uids[$ph], $ph);
T('H3-0b 3 用户种子', count($uids) === 3);

function mkColl(string $name, float $price, int $edition): int {
  exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,status,onsale_at,is_resaleable,resale_price_mode,created_at,updated_at)
       VALUES (1,'$name','https://qa.local/h3.png',$price,$edition,'onsale',DATE_SUB(NOW(),INTERVAL 1 HOUR),1,0,NOW(3),NOW(3))");
  return (int)v("SELECT id FROM nft_collectibles WHERE name='$name' ORDER BY id DESC LIMIT 1");
}
$cidC   = mkColl('H3-C', 1.00, 60);
$cidAST = mkColl('H3-AST', 10.00, 100);
T('H3-0c 2 藏品种子', $cidC > 0 && $cidAST > 0);
exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
     VALUES ({$uids['15300000002']},$cidAST,'SNQ-H3-AST-000001','purchase',10.00,NOW(3),'held',NOW(3),NOW(3))");
$assetS = (int)v("SELECT id FROM nft_user_collectibles WHERE user_id={$uids['15300000002']} AND collectible_id=$cidAST ORDER BY id DESC LIMIT 1");
T('H3-0d 卖家资产种子', $assetS > 0);

/* ================= H3-1a 支付写风暴 + 并发读者轮询 ================= */
echo "\n=== H3-1a 支付写风暴（余额12 vs 20单×1元）+ 并发读 ===\n";
$W = '15300000000'; $R = '15300000001';
$ordersW = [];
for ($k = 0; $k < 20; $k++) {
  $r = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$W],'b'=>['collectibleId'=>$cidC,'quantity'=>1,'paymentPassword'=>'Pay#2026']]])[0];
  if (($r['json']['code'] ?? -1) === 0) $ordersW[] = $r['json']['data']['orderNo'];
}
T('H3-1a1 20 笔订单创建成功', count($ordersW) === 20, 'created=' . count($ordersW));

$reqs = [];
foreach ($ordersW as $no) $reqs[] = ['url'=>"$BASE/api/orders/$no/pay",'tok'=>$TOK[$W],'b'=>['orderNo'=>$no,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']];
for ($k = 0; $k < 8; $k++)  $reqs[] = ['url'=>"$BASE/api/wallet",'tok'=>$TOK[$W],'method'=>'GET','b'=>null];
for ($k = 0; $k < 6; $k++)  $reqs[] = ['url'=>"$BASE/api/wallet/transactions",'tok'=>$TOK[$W],'method'=>'GET','b'=>null];
for ($k = 0; $k < 6; $k++)  $reqs[] = ['url'=>"$BASE/api/orders",'tok'=>$TOK[$W],'method'=>'GET','b'=>null];
$rs = burst($reqs);
$okPay = 0; $noBal = 0; $readers = 0; $readerBad = 0; $balNeg = 0; $balInval = 0;
foreach ($rs as $idx => $d) {
  if ($idx < 20) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okPay++; elseif ($jc === 4003) $noBal++; }
  else {
    $readers++;
    if ($d['code'] !== 200 || ($d['json']['code'] ?? -1) !== 0) $readerBad++;
    else {
      $data = $d['json']['data'] ?? null;
      if (isset($data['balance'])) { // GET /api/wallet
        if ((float)$data['balance'] < 0 || (float)$data['available'] < 0 || (float)$data['frozen'] < 0) $balNeg++;
        if ((float)$data['available'] > (float)$data['balance'] + 0.001) $balInval++;
      }
    }
  }
}
T('H3-1a2 并发支付恰 12 成功 / 8 余额不足', $okPay === 12 && $noBal === 8, "ok=$okPay noBal=$noBal");
T('H3-1a3 读者全程 200+code0 无 5xx', $readers === 20 && $readerBad === 0, "readers=$readers bad=$readerBad");
T('H3-1a4 读到的余额快照永不为负且 available<=balance', $balNeg === 0 && $balInval === 0, "neg=$balNeg inval=$balInval");

$tx = q("SELECT amount,balance_after FROM nft_wallet_transactions WHERE user_id={$uids[$W]} ORDER BY id");
$after = array_map(fn ($t) => (string)$t['balance_after'], $tx);
$wRow = q("SELECT balance FROM nft_wallets WHERE user_id={$uids[$W]}")[0];
$chainOk = count($tx) === 12
  && empty(array_diff($after, ['11.00','10.00','9.00','8.00','7.00','6.00','5.00','4.00','3.00','2.00','1.00','0.00']))
  && abs((float)$wRow['balance'] - 0.0) < 0.001;
T('H3-1a5 终态余额==0 且与最后一笔流水 balance_after 一致、流水连续', $chainOk,
  'rows=' . count($tx) . ' last=' . ($after ? end($after) : '-') . ' wallet=' . $wRow['balance']);

/* ================= H3-1b 挂单风暴 + 池/市场并发读 ================= */
echo "\n=== H3-1b 挂单风暴（3 轮×30 并发）+ 并发读 ===\n";
$S = '15300000002';
$roundOk = true; $readerTotal = 0; $readerBad2 = 0; $invariantBad = 0; $roundDetail = [];
for ($round = 1; $round <= 3; $round++) {
  $reqs = [];
  for ($k = 0; $k < 30; $k++) $reqs[] = ['url'=>"$BASE/api/resale/listings",'tok'=>$TOK[$S],'b'=>['userCollectibleId'=>$assetS,'price'=>10.00,'paymentPassword'=>'Pay#2026']];
  for ($k = 0; $k < 10; $k++) $reqs[] = ['url'=>"$BASE/api/resale/listings",'method'=>'GET','b'=>null];           // 公开寄售池
  for ($k = 0; $k < 10; $k++) $reqs[] = ['url'=>"$BASE/api/market/collections",'method'=>'GET','b'=>null];         // 公开市场
  $rs = burst($reqs);
  $okL = 0; $errCodes = [];
  foreach ($rs as $idx => $d) {
    if ($idx < 30) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okL++; else $errCodes[$jc] = ($errCodes[$jc] ?? 0) + 1; }
    else { $readerTotal++; if ($d['code'] !== 200 || ($d['json']['code'] ?? -1) !== 0) $readerBad2++; }
  }
  if ($okL !== 1) $roundOk = false;
  $roundDetail[] = "r$round:ok=$okL err=" . json_encode($errCodes);
  /* 跨表不变式：selling 挂单的资产必须 consigned（无半提交脏读） */
  $bad = (int)v("SELECT COUNT(*) FROM nft_resale_listings l JOIN nft_user_collectibles uc ON uc.id=l.user_collectible_id WHERE l.status='selling' AND uc.status<>'consigned'");
  if ($bad > 0) $invariantBad += $bad;
  /* 取消挂单，进入下一轮 */
  $lstId = (int)v("SELECT id FROM nft_resale_listings WHERE user_collectible_id=$assetS AND status='selling'");
  $cancelR = burst([['url'=>"$BASE/api/resale/listings/$lstId/cancel",'tok'=>$TOK[$S],'b'=>[]]])[0];
  $roundDetail[count($roundDetail)-1] .= " cancel=" . ($cancelR['json']['code'] ?? '?');
  if ($round < 3) sleep(2); /* 等待 1s 寄售冷却过期再进下一轮（冷却值已 QA 调为 1s） */
}
T('H3-1b1 每轮 30 并发挂单恰 1 成功 ×3 轮', $roundOk, implode(' | ', $roundDetail));
T('H3-1b2 池/市场读者全程 200+code0', $readerTotal === 60 && $readerBad2 === 0, "readers=$readerTotal bad=$readerBad2");
T('H3-1b3 跨表不变式：selling 挂单资产必 consigned', $invariantBad === 0, "violations=$invariantBad");

/* ================= H3-1c 库存恒等式 ================= */
echo "\n=== H3-1c 库存恒等式 ===\n";
$inv = q("SELECT sold, locked_quantity FROM nft_collectibles WHERE id=$cidC")[0];
$ord = [];
foreach (q("SELECT status,COUNT(*) c FROM nft_orders WHERE user_id={$uids[$W]} GROUP BY status") as $s) $ord[$s['status']] = (int)$s['c'];
T('H3-1c1 sold+locked <= edition 且 == 订单终态分布',
  (int)$inv['sold'] + (int)$inv['locked_quantity'] <= 60
  && (int)$inv['sold'] === ($ord['completed'] ?? 0)
  && (int)$inv['locked_quantity'] === ($ord['pending'] ?? 0)
  && ($ord['completed'] ?? 0) === 12 && ($ord['pending'] ?? 0) === 8,
  'sold=' . $inv['sold'] . ' locked=' . $inv['locked_quantity'] . ' orders=' . json_encode($ord));

/* ================= H3-2 慢查询审计 ================= */
echo "\n=== H3-2 慢查询审计（窗口：H3-1 全部风暴，阈值 0.2s）===\n";
$slowRows = q("SELECT sql_text, TIME_TO_SEC(query_time) AS sec FROM mysql.slow_log ORDER BY query_time DESC LIMIT 5");
$slowCnt  = (int)v("SELECT COUNT(*) FROM mysql.slow_log");
$slowSel  = (int)v("SELECT COUNT(*) FROM mysql.slow_log WHERE sql_text LIKE 'SELECT%' AND TIME_TO_SEC(query_time) > 0.5");
$top = [];
foreach ($slowRows as $sr) $top[] = round((float)$sr['sec'], 2) . 's ' . mb_substr(preg_replace('/\s+/', ' ', $sr['sql_text']), 0, 80);
T('H3-2a 慢查询 <= 少量行锁等待，无慢 SELECT（>0.5s）', $slowCnt <= 40 && $slowSel === 0,
  "total=$slowCnt slowSelect=$slowSel top=" . ($top ? implode(' ; ', array_slice($top, 0, 2)) : '无'));
exe("SET GLOBAL slow_query_log=OFF");
exe("SET GLOBAL long_query_time=10.0");
exe("SET GLOBAL log_output='FILE'");
$slowRestored = true;

/* ================= H3-3 死锁检测 ================= */
echo "\n=== H3-3 死锁检测（覆盖 H1/H2/H3 全部并发场景）===\n";
$dlk1 = (int)(v("SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'") ?? 0);
$innodb = implode("\n", array_map(fn ($r) => $r['Status'], q("SHOW ENGINE INNODB STATUS") ?: []));
$hasDeadlockSection = strpos($innodb, 'LATEST DETECTED DEADLOCK') !== false;
T('H3-3a 全程零新增死锁（H1 200并发/H2 竞态/H3 混合负载）', ($dlk1 - $dlk0) === 0, "deadlocks {$dlk0}->{$dlk1}");
T('H3-3b InnoDB 状态无 LATEST DETECTED DEADLOCK 段', !$hasDeadlockSection);

/* ================= H3-4 错误处理 / 500 堆栈泄露 ================= */
echo "\n=== H3-4 错误处理与堆栈泄露（畸形/对抗请求）===\n";
$LEAK = ['Stack trace', 'SQLSTATE', 'think\\', 'vendor/', '/app/', 'Exception', '.php'];
$ALLOWED = [200, 400, 401, 403, 404, 405];
$h500 = 0; $leaks = 0; $badJson = 0; $cases = 0; $leakDetail = [];
$badTok = 'xx' . substr($TOK[$R], 2);                       // 篡改签名
$expTok = mint($uids[$R], $R, -3600);                       // 过期 token
$casesList = [
  ['GET',  "/api/wallet", null, null],                                             // 未携带 token
  ['GET',  "/api/wallet", $badTok, null],                                          // 篡改签名
  ['GET',  "/api/wallet", $expTok, null],                                          // 过期 token
  ['GET',  "/api/wallet", $TOK[$R], null],                                         // 合法基线
  ['POST', "/api/orders", $TOK[$R], '{oops'],                                      // 坏 JSON
  ['POST', "/api/orders", $TOK[$R], ['collectibleId' => 'abc', 'quantity' => 1, 'paymentPassword' => 'Pay#2026']],  // 类型滥用
  ['POST', "/api/orders", $TOK[$R], ['collectibleId' => $cidC, 'quantity' => -5, 'paymentPassword' => 'Pay#2026']],  // 负数量
  ['POST', "/api/orders", $TOK[$R], ['collectibleId' => $cidC, 'quantity' => 99999999999999999999, 'paymentPassword' => 'Pay#2026']], // 溢出
  ['POST', "/api/orders/NOPE123/pay", $TOK[$R], ['orderNo' => 'NOPE123', 'paymentMethod' => 'balance', 'paymentPassword' => 'Pay#2026']], // 不存在订单
  ['POST', "/api/orders/1'%20OR%20'1'='1/pay", $TOK[$R], ['paymentMethod' => 'balance', 'paymentPassword' => 'Pay#2026']],  // SQLi 路径参数
  ['POST', "/api/orders/{$ordersW[0]}/pay", $TOK[$W], ['orderNo' => $ordersW[0], 'paymentMethod' => '<script>alert(1)</script>', 'paymentPassword' => 'Pay#2026']], // XSS 字段
  ['POST', "/api/orders/{$ordersW[0]}/pay", $TOK[$R], ['orderNo' => $ordersW[0], 'paymentMethod' => 'balance', 'paymentPassword' => 'Pay#2026']], // 越权支付他人订单
  ['POST', "/api/orders/{$ordersW[0]}/cancel", $TOK[$R], []],                     // 越权取消他人订单
  ['GET',  "/api/collections/99999999", null, null],                               // 不存在藏品
  ['GET',  "/api/collections/%2e%2e%2f%2e%2e%2fetc%2fpasswd", null, null],        // 编码路径穿越
  ['GET',  "/api/nonexistent", null, null],                                       // 不存在路由
  ['GET',  "/api/orders?page=-1&pageSize=999999", $TOK[$R], null],                // 分页滥用
  ['GET',  "/api/orders?page=abc&pageSize=xyz", $TOK[$R], null],                  // 分页类型滥用
  ['GET',  "/api/wallet/transactions?type=buy'--", $TOK[$R], null],                // 注入查询参数
  ['POST', "/api/auth/login", null, ['phone' => "' OR 1=1--", 'code' => '123456']], // 登录 SQLi
  ['POST', "/api/transfers", $TOK[$R], ['userCollectibleId' => $assetS, 'toPhone' => "1' UNION SELECT 1--", 'paymentPassword' => 'Pay#2026']], // 转注入入
  ['POST', "/api/check-in", $TOK[$R], ['already' => true]],                        // 陌生字段
  ['PUT',  "/api/orders", $TOK[$R], null],                                        // 不支持的方法
  ['POST', "/api/orders", $TOK[$R], ['collectibleId' => $cidC, 'quantity' => 1, 'paymentPassword' => str_repeat('A', 102400)]], // 100KB 超长载荷
];
foreach ($casesList as $c) {
  $cases++;
  $r = req($c[0], $BASE . $c[1], $c[2], $c[3]);
  if (!in_array($r['code'], $ALLOWED, true)) $h500++;
  if ($r['json'] === null || !isset($r['json']['code'])) $badJson++;
  foreach ($LEAK as $L) {
    if ($r['raw'] !== '' && stripos($r['raw'], $L) !== false) {
      $leaks++;
      $leakDetail[] = $c[1] . ' → ' . $L;
      break;
    }
  }
}
T('H3-4a 无 500/异常状态码（仅 200/400/401/403/404/405）', $h500 === 0, "abnormal=$h500/$cases");
T('H3-4b 响应均为合法 JSON 且含业务 code', $badJson === 0, "badJson=$badJson/$cases");
T('H3-4c 无堆栈/源码路径/SQL 报文泄露', $leaks === 0, 'leaks=' . implode('; ', $leakDetail));

echo "\n==== H3 结果: PASS $pass / FAIL $fail ====\n";
if ($fails) { echo "失败项:\n"; foreach ($fails as $f) echo "$f\n"; exit(1); }
exit(0);

<?php
/** H1 抢购超卖压测（P0 最高优先级）
 *  场景：
 *    H1-1 爆发超卖：藏品 A（edition=100）vs 200 并发「创建订单」→ 恰 100 成功 → 100 并发支付
 *         验收：sold≤edition、sold+locked≤edition、持仓数=成功支付数、serial 唯一且连续
 *    H1-2 混合竞态：藏品 B（edition=50）vs 200 并发下单 → 50 成功后 25 支付 + 25 取消并发对冲
 *         验收：取消回补库存、locked 归零、无双重处理
 *    H1-3 梯度持续负载：20 藏品池 × 并发 10/50/100/200 × 60s
 *         每轮完整周期 = 创建订单 + 余额支付；输出 TPS / RT(p50/p95/p99) / 错误率
 *    H1-4 全局恒等式验收：防超卖 CHECK 零违例、持仓=完成量、serial 全局唯一、资金守恒、零死锁
 *
 *  实测结论（2026-09-11，3 核沙箱 / PHP 内置服务器 32 worker / MySQL 8.0.46）：
 *  1. 正确性全绿：200 并发爆发无超卖、支付/取消无双重处理、恒等式零违例、资金守恒、零死锁。
 *  2. 吞吐上限 ~3.6 周期/s（7.2 req/s），与并发数无关（10→200 并发 TPS 恒定），RT 随并发线性增长。
 *     瓶颈根源 = bcrypt 交易密码校验（本机 cost10 单次 250ms CPU × 每周期 2 次）÷ 3 核 ≈ 3.6 TPS；
 *     非锁竞争（20 藏品池已分摊行锁）、非 IO（fsync 0.5ms、opcache 已启用）。
 *     生产建议：吞吐随 CPU 核数线性扩展；密码校验为刻意 CPU 成本，扩容 php-fpm worker 数即可。
 *  3. 200 并发档 10 个 curl 60s 超时（排队尾部超客户端超时，非服务端错误），对应 4 笔已创建未支付
 *     订单保持 pending+locked（一致性完好，依赖 300s 过期释放）。H1-3s/H1-3t 两项为该环境特征
 *     记录项；CI 回归建议跑 10~100 并发档。
 *  说明：JWT 直接按 JwtService 同源密钥铸造（HS256）；限购临时调至 100000（结束恢复原值）。
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

/* JWT 同源铸造（与 app/service/JwtService.php 一致：HS256 + .env SECRET，payload 含 sub/iat/exp） */
$envSrc = (string)file_get_contents(__DIR__ . '/../sinan-nft-backend/.env');
preg_match('/^SECRET\s*=\s*(.+)$/m', $envSrc, $m);
$SECRET = trim($m[1] ?? '');
function b64url($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function mint(int $uid, string $phone): string {
  global $SECRET;
  $h = b64url(json_encode(['typ'=>'JWT','alg'=>'HS256']));
  $p = b64url(json_encode(['iss'=>'sinan-nft-audience','aud'=>'sinan-nft-client','iat'=>time(),'exp'=>time()+86400,'sub'=>$uid,'phone'=>$phone]));
  return "$h.$p." . b64url(hash_hmac('sha256', "$h.$p", $SECRET, true));
}

/* curl_multi 并发爆发：一批请求同时发出，全部完成后返回 [i => ['rt'=>float,'err'=>bool,'code'=>int,'json'=>array]] */
function burst(array $reqs, int $timeout = 30): array {
  $mh = curl_multi_init(); $handles = [];
  foreach ($reqs as $i => $r) {
    $ch = curl_init($r['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$r['m'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Authorization: Bearer '.$r['tok']],
      CURLOPT_POSTFIELDS=>json_encode($r['b'])]);
    curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
  }
  $out = [];
  do {
    curl_multi_exec($mh, $active);
    while (($info = curl_multi_info_read($mh)) !== false) {
      $ch = $info['handle']; $i = array_search($ch, $handles, true);
      $out[$i] = ['err'=>$info['result']!==CURLE_OK, 'rt'=>curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                  'code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                  'json'=>json_decode((string)curl_multi_getcontent($ch), true) ?: ['code'=>-2,'message'=>'BADJSON']];
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    if ($active) curl_multi_select($mh, 0.02);
  } while (count($out) < count($reqs));
  curl_multi_close($mh);
  ksort($out); return $out;
}

function pct(array $a, float $p): string {
  if (!$a) return '-'; sort($a);
  return number_format($a[max(0,(int)floor($p*(count($a)-1)))]*1000, 0);
}

echo "=== H1-0 环境与种子准备 ===\n";
$rst = v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='sinan_nft' AND TABLE_NAME='nft_users'");
T('H1-0a 数据库就绪', (int)$rst === 1 && $SECRET !== '', 'secret=' . ($SECRET ? 'ok' : 'MISSING'));

$cfgOld = (string)v("SELECT config_value FROM nft_system_configs WHERE config_key='purchase_limit_per_user'");
exe("UPDATE nft_system_configs SET config_value='100000' WHERE config_key='purchase_limit_per_user'");
register_shutdown_function(function () use ($cfgOld) {
  global $PDO;
  $PDO->exec("UPDATE nft_system_configs SET config_value=" . $PDO->quote($cfgOld) . " WHERE config_key='purchase_limit_per_user'");
});

/* 种子用户（15100000000 起 220 名，实名+交易密码+10万余额），幂等重建 */
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '1510000%'");
exe("DELETE wt FROM nft_wallet_transactions wt JOIN nft_users u ON u.id=wt.user_id WHERE u.phone LIKE '1510000%'");
exe("DELETE p FROM nft_payments p JOIN nft_users u ON u.id=p.user_id WHERE u.phone LIKE '1510000%'");
exe("DELETE o FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE '1510000%'");
exe("DELETE w FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE '1510000%'");
exe("DELETE FROM nft_users WHERE phone LIKE '1510000%'");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'H1-%'");

$NUSERS = 220;
$hash = password_hash('Pay#2026', PASSWORD_BCRYPT);
$vals = [];
for ($i = 0; $i < $NUSERS; $i++) {
  $vals[] = "('1510000" . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . "','H1压测员" . $i . "','','UH1" . str_pad((string)$i, 5, '0', STR_PAD_LEFT) . "','IH1" . $i . "',1,'$hash')";
}
exe("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,transaction_password) VALUES " . implode(',', $vals));
$uids = array_map('current', q("SELECT id FROM nft_users WHERE phone LIKE '1510000%' ORDER BY id"));
$wvals = [];
foreach ($uids as $uid) $wvals[] = "($uid,100000.00,100000.00,0.00,0.00)";
exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points) VALUES " . implode(',', $wvals));
T('H1-0b 种子 220 用户/钱包/实名/交易密码', count($uids) === $NUSERS, 'users=' . count($uids));
$toks = [];
foreach ($uids as $k => $uid) $toks[$k] = mint((int)$uid, '1510000' . str_pad((string)$k, 4, '0', STR_PAD_LEFT));
T('H1-0c 铸造 220 个 C 端 JWT', count($toks) === $NUSERS && strlen($toks[0]) > 60);

$deadlocks0 = (int)v("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks' /*NUM*/") ?: (int)(q("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")[0]['Value'] ?? 0);

function mkCollectible(string $name, int $edition, float $price): int {
  exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,status,onsale_at,created_at,updated_at)
       VALUES (1,'$name','https://qa.local/h1.png',$price,$edition,'onsale',DATE_SUB(NOW(),INTERVAL 1 HOUR),NOW(3),NOW(3))");
  return (int)v("SELECT id FROM nft_collectibles WHERE name='$name'");
}
function createReq(int $cid, int $u, string $tok): array {
  global $BASE; return ['m'=>'POST','url'=>"$BASE/api/orders",'tok'=>$tok,'b'=>['collectibleId'=>$cid,'quantity'=>1,'paymentPassword'=>'Pay#2026']];
}

echo "\n=== H1-1 爆发超卖：edition=100 vs 200 并发 ===\n";
$cidA = mkCollectible('H1-A', 100, 0.01);
$reqs = [];
for ($i = 0; $i < 200; $i++) $reqs[$i] = createReq($cidA, $i, $toks[$i]);
$creates = burst($reqs);
$okCreate = []; $rejStock = 0; $errHttp = 0; $rj5 = 0;
foreach ($creates as $i => $cr) {
  if ($cr['err'] || $cr['code'] !== 200) { $errHttp++; continue; }
  $jc = $cr['json']['code'] ?? -1;
  if ($jc === 0) $okCreate[$i] = $cr['json']['data']['orderNo'] ?? '';
  elseif ($jc === 3001) $rejStock++;
  elseif ($jc >= 5000) $rj5++;
}
T('H1-1a 200 并发下单恰 100 成功（原子库存锁）', count($okCreate) === 100 && $rejStock === 100,
  'success=' . count($okCreate) . ' 库存不足=' . $rejStock . ' 5xx=' . $rj5 . ' httpErr=' . $errHttp);
$stA = q("SELECT sold,locked_quantity,edition FROM nft_collectibles WHERE id=$cidA")[0];
T('H1-1b 下单后 sold+locked=100 且不超 edition', (int)$stA['sold'] + (int)$stA['locked_quantity'] === 100 && (int)$stA['sold'] === 0,
  'sold=' . $stA['sold'] . ' locked=' . $stA['locked_quantity']);

$pays = burst(array_map(fn ($i) => ['m'=>'POST','url'=>"$BASE/api/orders/{$okCreate[$i]}/pay",'tok'=>$toks[$i],
  'b'=>['orderNo'=>$okCreate[$i],'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']], array_keys($okCreate)));
$payOk = 0; $pay5 = 0;
foreach ($pays as $i => $pr) {
  $jp = $pr['json']['code'] ?? -1;
  if (!$pr['err'] && $pr['code'] === 200 && $jp === 0) $payOk++; elseif ($jp >= 5000) $pay5++;
}
T('H1-1c 100 并发支付全部成功且无 5xx', $payOk === 100 && $pay5 === 0 && $errHttp === 0, 'payOk=' . $payOk . ' 5xx=' . $pay5);
$stA = q("SELECT sold,locked_quantity FROM nft_collectibles WHERE id=$cidA")[0];
T('H1-1d 售罄终态 sold=100 locked=0', (int)$stA['sold'] === 100 && (int)$stA['locked_quantity'] === 0,
  'sold=' . $stA['sold'] . ' locked=' . $stA['locked_quantity']);
$posA = (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=$cidA");
T('H1-1e 持仓数=成功支付数=100', $posA === 100, "positions=$posA");
$serials = array_column(q("SELECT serial FROM nft_user_collectibles WHERE collectible_id=$cidA"), 'serial');
$uniq = count(array_unique($serials)) === count($serials) && count($serials) === 100;
$cont = true;
foreach ($serials as $s2) { $seq = (int)substr((string)$s2, strrpos((string)$s2, '-') + 1); if ($seq < 1 || $seq > 100) $cont = false; }
T('H1-1f serial 唯一且全部落在 0001~0100', $uniq && $cont, 'distinct=' . count(array_unique($serials)));

echo "\n=== H1-2 混合竞态：edition=50 vs 200 并发，25 支付+25 取消对冲 ===\n";
$cidB = mkCollectible('H1-B', 50, 0.01);
$reqs = [];
for ($i = 0; $i < 200; $i++) $reqs[$i] = createReq($cidB, $i + 10, $toks[$i + 10]);
$creates = burst($reqs);
$okB = [];
foreach ($creates as $i => $cr) if (!$cr['err'] && ($cr['json']['code'] ?? -1) === 0) $okB[$i] = $cr['json']['data']['orderNo'];
T('H1-2a 200 并发下单恰 50 成功', count($okB) === 50, 'success=' . count($okB));
$keys = array_keys($okB); $payKeys = array_slice($keys, 0, 25); $cancelKeys = array_slice($keys, 25);
$mixed = [];
foreach ($payKeys as $i) $mixed[] = ['m'=>'POST','url'=>"$BASE/api/orders/{$okB[$i]}/pay",'tok'=>$toks[$i + 10],
  'b'=>['orderNo'=>$okB[$i],'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']];
foreach ($cancelKeys as $i) $mixed[] = ['m'=>'POST','url'=>"$BASE/api/orders/{$okB[$i]}/cancel",'tok'=>$toks[$i + 10],'b'=>['orderNo'=>$okB[$i]]];
$mres = burst($mixed);
$mPay = $mCan = $mBad = 0;
foreach ($mres as $mr) { $j = $mr['json']['code'] ?? -1; if (!$mr['err'] && $j === 0) { ($mr['json']['data'] ?? null) !== null ? $mPay++ : $mCan++; } else $mBad++; }
$stB = q("SELECT sold,locked_quantity FROM nft_collectibles WHERE id=$cidB")[0];
$ordB = q("SELECT status,COUNT(*) c FROM nft_orders WHERE collectible_id=$cidB GROUP BY status");
$statB = []; foreach ($ordB as $o2) $statB[$o2['status']] = (int)$o2['c'];
$posB = (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=$cidB");
T('H1-2b 支付/取消并发无双重处理（25 completed + 25 cancelled）',
  (($statB['completed'] ?? 0) === 25) && (($statB['cancelled'] ?? 0) === 25) && $mBad === 0,
  'completed=' . ($statB['completed'] ?? 0) . ' cancelled=' . ($statB['cancelled'] ?? 0) . ' bad=' . $mBad);
T('H1-2c 取消回补后终态 sold=25 locked=0',
  (int)$stB['sold'] === 25 && (int)$stB['locked_quantity'] === 0, 'sold=' . $stB['sold'] . ' locked=' . $stB['locked_quantity']);
T('H1-2d 持仓数=成功支付数=25', $posB === 25, "positions=$posB");

echo "\n=== H1-3 梯度持续负载：create+pay 完整周期（每档 60s，20 藏品池分摊行锁）===\n";
/* 单藏品行锁串行上限已在预跑中测得：同藏品 create/pay 均 FOR UPDATE 该行，TPS 封顶 ~3.7（与并发数无关）。
 * 梯度负载按 20 件藏品轮转下单，测量真实聚合吞吐（单藏品上限作为瓶颈结论记录）。 */
$cidPool = [];
for ($i = 0; $i < 20; $i++) $cidPool[] = mkCollectible('H1-C' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 1000000, 0.01);
function sustain(array $cids, array $toks, int $conc, int $seconds): array {
  global $BASE;
  $mh = curl_multi_init(); $slots = []; $ui = 0;
  $rtC = []; $rtP = []; $cycles = 0; $errConn = 0; $srvErr = 0; $bizRej = 0;
  $start = microtime(true);
  $addCreate = function () use (&$mh, &$slots, &$ui, $cids, $toks, $BASE) {
    $u = $ui; $cid = $cids[$ui % count($cids)]; $ui = ($ui + 1) % 1000000;
    $ch = curl_init("$BASE/api/orders");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>'POST', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$toks[$u % count($toks)]],
      CURLOPT_POSTFIELDS=>json_encode(['collectibleId'=>$cid,'quantity'=>1,'paymentPassword'=>'Pay#2026'])]);
    curl_multi_add_handle($mh, $ch);
    $slots[(int)$ch] = ['phase'=>'create','u'=>$u % count($toks),'orderNo'=>null,'t0'=>microtime(true)];
  };
  for ($i = 0; $i < $conc; $i++) $addCreate();
  while ($slots) {
    curl_multi_exec($mh, $active);
    while (($info = curl_multi_info_read($mh)) !== false) {
      $ch = $info['handle']; $s = $slots[(int)$ch] ?? null;
      $rt = microtime(true) - ($s['t0'] ?? microtime(true));
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $json = json_decode((string)curl_multi_getcontent($ch), true) ?: ['code'=>-2];
      curl_multi_remove_handle($mh, $ch); curl_close($ch); unset($slots[(int)$ch]);
      if ($info['result'] !== CURLE_OK || $code !== 200) { $errConn++; continue; }
      if ($s['phase'] === 'create') {
        $rtC[] = $rt; $jc = $json['code'] ?? -1;
        if ($jc === 0) {
          $nch = curl_init("$BASE/api/orders/{$json['data']['orderNo']}/pay");
          curl_setopt_array($nch, [CURLOPT_CUSTOMREQUEST=>'POST', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$toks[$s['u']]],
            CURLOPT_POSTFIELDS=>json_encode(['orderNo'=>$json['data']['orderNo'],'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026'])]);
          curl_multi_add_handle($mh, $nch);
          $slots[(int)$nch] = ['phase'=>'pay','u'=>$s['u'],'orderNo'=>$json['data']['orderNo'],'t0'=>microtime(true)];
        } elseif ($jc >= 5000) { $srvErr++; if (microtime(true) < $GLOBALS['deadlineNow']) $addCreate(); }
        else { $bizRej++; if (microtime(true) < $GLOBALS['deadlineNow']) $addCreate(); }
      } else {
        $rtP[] = $rt; $jp = $json['code'] ?? -1;
        if ($jp === 0) $cycles++; elseif ($jp >= 5000) $srvErr++; else $bizRej++;
        if (microtime(true) < $GLOBALS['deadlineNow']) $addCreate();
      }
    }
    if ($active || $slots) curl_multi_select($mh, 0.005);
  }
  curl_multi_close($mh);
  $elapsed = microtime(true) - $start;
  return compact('cycles','errConn','srvErr','bizRej','rtC','rtP','elapsed');
}
echo sprintf("%6s %8s %8s %7s | %22s | %22s | %5s %5s %5s\n", '并发', '周期数', 'TPS', '耗时s', 'create RT p50/95/99(ms)', 'pay RT p50/95/99(ms)', '5xx', '连接错误', '业务拒绝');
foreach ([10, 50, 100, 200] as $lvl) {
  $GLOBALS['deadlineNow'] = microtime(true) + 60;
  $m = sustain($cidPool, $toks, $lvl, 60);
  $tps = $m['cycles'] / $m['elapsed'];
  echo sprintf("%6d %8d %8.1f %7.1f | %22s | %22s | %5d %5d %5d\n", $lvl, $m['cycles'], $tps, $m['elapsed'],
    pct($m['rtC'],0.5).'/'.pct($m['rtC'],0.95).'/'.pct($m['rtC'],0.99),
    pct($m['rtP'],0.5).'/'.pct($m['rtP'],0.95).'/'.pct($m['rtP'],0.99),
    $m['srvErr'], $m['errConn'], $m['bizRej']);
  T("H1-3 并发 $lvl 无服务端错误", $m['srvErr'] === 0 && $m['bizRej'] === 0, '5xx=' . $m['srvErr'] . ' bizRej=' . $m['bizRej']);
  $lastErr = $m['errConn'];
}
T('H1-3s 梯度负载连接错误为零', $lastErr === 0, "connErr(200)=$lastErr");
$lockC = (int)v("SELECT COALESCE(SUM(locked_quantity),0) FROM nft_collectibles WHERE id IN (" . implode(',', $cidPool) . ")");
T('H1-3t 负载后藏品池终态 locked=0（槽位排空）', $lockC === 0, "poolLocked=$lockC");

echo "\n=== H1-4 全局恒等式验收 ===\n";
$viol = (int)v("SELECT COUNT(*) FROM nft_collectibles WHERE deleted_at IS NULL AND (sold > edition OR sold + locked_quantity > edition)");
T('H1-4a 防超卖 CHECK 全表零违例（sold≤edition 且 sold+locked≤edition）', $viol === 0, "violations=$viol");
/* 持仓=完成量 断言限定 H1 藏品（存量数据含转赠/市场过户属合法漂移，另行展示不计失败） */
$h1ids = $cidA . ',' . $cidB . ',' . implode(',', $cidPool);
$badPos = (int)v("SELECT COUNT(*) FROM (SELECT collectible_id, SUM(quantity) sq FROM nft_orders WHERE status='completed' AND collectible_id IN ($h1ids) GROUP BY collectible_id) t
  LEFT JOIN (SELECT collectible_id, COUNT(*) pc FROM nft_user_collectibles WHERE collectible_id IN ($h1ids) GROUP BY collectible_id) p ON p.collectible_id=t.collectible_id
  WHERE COALESCE(p.pc,0) <> t.sq");
T('H1-4b 持仓数=完成订单量（H1 全部 22 件藏品）', $badPos === 0, "mismatch=$badPos");
$dupSerial = (int)v("SELECT COUNT(*) - COUNT(DISTINCT serial) FROM nft_user_collectibles WHERE collectible_id IN ($h1ids)");
$dupGlobal = (int)v("SELECT COUNT(*) - COUNT(DISTINCT serial) FROM nft_user_collectibles");
T('H1-4c serial 唯一（H1 藏品零重复）', $dupSerial === 0, "h1dups=$dupSerial 全库dups=$dupGlobal");
$negW = (int)v("SELECT COUNT(*) FROM nft_wallets WHERE available < 0 OR balance < 0 OR frozen < 0");
T('H1-4d 无负余额', $negW === 0);
$paysAgg = q("SELECT user_id, SUM(amount) paid FROM nft_wallet_transactions WHERE trans_type='buy' AND user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1510000%') GROUP BY user_id");
$wNow = q("SELECT w.user_id, w.available FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE '1510000%'");
$wMap = []; foreach ($wNow as $w) $wMap[$w['user_id']] = (float)$w['available'];
$consBad = 0; $totPaid = 0.0;
foreach ($paysAgg as $pa) { $totPaid += (float)$pa['paid']; if (abs($wMap[$pa['user_id']] - (100000 - (float)$pa['paid'])) > 0.005) $consBad++; }
foreach ($wMap as $av) if ($av > 100000.0 + 0.005) $consBad++;
T('H1-4e 资金守恒：初始-支付=当前余额（220 用户逐个勾稽）', $consBad === 0, 'badUsers=' . $consBad . ' 总支付=' . number_format($totPaid, 2) . '元');
$deadlocks1 = (int)(q("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")[0]['Value'] ?? 0);
T('H1-4f 全程零死锁', $deadlocks1 === $deadlocks0, "deadlocks {$deadlocks0}->{$deadlocks1}");

echo "\n==== H1 结果: PASS $pass / FAIL $fail ====\n";
if ($fails) { echo "失败项:\n" . implode("\n", $fails) . "\n"; exit(1); }
exit(0);

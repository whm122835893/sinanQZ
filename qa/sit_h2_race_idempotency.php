<?php
/** H2 余额竞态/幂等/事务原子性
 *  场景：
 *    H2-1 同钱包并发支付：余额 10 元用户 30 笔 pending 订单并发支付 → 恰 10 成功 / 20 余额不足；
 *         无负余额、流水 10 笔、balance_after 连续、资金守恒
 *    H2-2 双击/重复幂等：pay 双击、cancel 双击、pay+cancel 对打、签到双击、
 *         免费抽奖顺序重抽/并发双抽（H2-D1 探针）、开盒双击、合成双击 —— 全部只生效一次
 *    H2-3 同资产并发挂单/转赠：30 并发 → 恰 1 成功（行锁 + 状态条件）
 *    H2-4 市场单中途失败全回滚：支付时卖家资产已被转移 → 3001 + 钱包/支付记录/订单零变动
 *  结论（首次运行）：H2-2e 两项探针 FAIL = 缺陷 H2-D1（免费抽奖不落台账可无限抽）；
 *  修复（LuckyDraw draw() 用户行锁 + 免费路径落 source=free 台账并立即消耗）后回归全绿。
 *  附注：H2-2b/H2-2d 首轮曾误报，为测试断言自身缺陷（2b 应比 lockBefore-1；2d 应用 PHP 时区日期
 *  而非 MySQL CURDATE()，因应用按 Asia/Shanghai 写 check_in_date），非产品缺陷，已修正断言。
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
function mint(int $uid, string $phone): string {
  global $SECRET;
  $h = b64url(json_encode(['typ'=>'JWT','alg'=>'HS256']));
  $p = b64url(json_encode(['iss'=>'sinan-nft-audience','aud'=>'sinan-nft-client','iat'=>time(),'exp'=>time()+86400,'sub'=>$uid,'phone'=>$phone]));
  return "$h.$p." . b64url(hash_hmac('sha256', "$h.$p", $SECRET, true));
}
function burst(array $reqs, int $timeout = 90): array {
  $mh = curl_multi_init(); $handles = [];
  foreach ($reqs as $i => $r) {
    $ch = curl_init($r['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>'POST', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$r['tok']],
      CURLOPT_POSTFIELDS=>json_encode($r['b'])]);
    curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
  }
  $out = [];
  do {
    curl_multi_exec($mh, $active);
    while (($info = curl_multi_info_read($mh)) !== false) {
      $ch = $info['handle']; $i = array_search($ch, $handles, true);
      $out[$i] = ['err'=>$info['result']!==CURLE_OK, 'code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                  'json'=>json_decode((string)curl_multi_getcontent($ch), true) ?: ['code'=>-2,'message'=>'BADJSON']];
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    if ($active) curl_multi_select($mh, 0.02);
  } while (count($out) < count($reqs));
  curl_multi_close($mh); ksort($out); return $out;
}

echo "=== H2-0 环境与种子 ===\n";
T('H2-0a 数据库与密钥就绪', (int)v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='sinan_nft' AND TABLE_NAME='nft_users'") === 1 && $SECRET !== '');
$cfgOld = (string)v("SELECT config_value FROM nft_system_configs WHERE config_key='purchase_limit_per_user'");
exe("UPDATE nft_system_configs SET config_value='100000' WHERE config_key='purchase_limit_per_user'");
register_shutdown_function(function () use ($cfgOld) {
  global $PDO;
  $PDO->exec("UPDATE nft_system_configs SET config_value=" . $PDO->quote($cfgOld) . " WHERE config_key='purchase_limit_per_user'");
});

/* 种子用户清理（152 前缀）+ 重建 —— 按外键依赖子表先删，避免 RESTRICT 阻断导致跨轮残留污染基数 */
/* 1) 动作/流水类子表（引用 orders / users） */
exe("DELETE p FROM nft_payments p JOIN nft_users u ON u.id=p.user_id WHERE u.phone LIKE '1520000%'");
exe("DELETE wt FROM nft_wallet_transactions wt JOIN nft_users u ON u.id=wt.user_id WHERE u.phone LIKE '1520000%'");
exe("DELETE FROM nft_lucky_draw_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
exe("DELETE FROM nft_check_in_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
/* 2) 引用 user_collectibles 的表（挂单/转赠/合成记录）必须先于 uc 删除；record_items 随记录级联 */
exe("DELETE FROM nft_resale_listings WHERE seller_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
exe("DELETE FROM nft_transfers WHERE from_user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%') OR to_user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
exe("DELETE FROM nft_synthesis_records WHERE user_id IN (SELECT id FROM nft_users WHERE phone LIKE '1520000%')");
/* 3) orders（payments 已删）→ user_collectibles（挂单/转赠/合成已删）→ wallets → users */
exe("DELETE o FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE '1520000%'");
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '1520000%'");
exe("DELETE w FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE '1520000%'");
exe("DELETE FROM nft_users WHERE phone LIKE '1520000%'");
/* 4) 藏品域：box_items → boxes；materials → activities；collectibles 最后 */
exe("DELETE FROM nft_blind_box_items WHERE blind_box_id IN (SELECT id FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H2-%'))");
exe("DELETE FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H2-%')");
exe("DELETE FROM nft_synthesis_materials WHERE activity_id IN (SELECT id FROM nft_synthesis_activities WHERE title LIKE 'H2%')");
exe("DELETE FROM nft_synthesis_activities WHERE title LIKE 'H2%'");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'H2-%'");

$hash = password_hash('Pay#2026', PASSWORD_BCRYPT);
$USERS = [ // phone => balance
  '15200000000' => 10.00,   // W 钱包竞态
  '15200000001' => 100.00, // U1 双击/对打/签到
  '15200000002' => 0.00,   // LD1 免费抽
  '15200000003' => 0.00,   // LD2 免费抽
  '15200000004' => 100.00, // S 挂单/转赠/卖家
  '15200000005' => 100.00, // M 市场买家
  '15200000006' => 100.00, // BB 开盒
  '15200000007' => 100.00, // SYN 合成
];
$i = 0; $vals = [];
foreach ($USERS as $ph => $bal) {
  $vals[] = "('$ph','H2-$i','','UH2" . str_pad((string)$i,4,'0',STR_PAD_LEFT) . "','IH2$i',1,'$hash')"; $i++;
}
exe("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,transaction_password) VALUES " . implode(',', $vals));
$uids = [];
foreach (q("SELECT id,phone FROM nft_users WHERE phone LIKE '1520000%'") as $u) $uids[$u['phone']] = (int)$u['id'];
$wvals = [];
foreach ($USERS as $ph => $bal) $wvals[] = "({$uids[$ph]},$bal,$bal,0.00,0.00)";
exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points) VALUES " . implode(',', $wvals));
$TOK = [];
foreach ($USERS as $ph => $bal) $TOK[$ph] = mint($uids[$ph], $ph);
T('H2-0b 8 用户种子', count($uids) === 8);

function mkColl(string $name, float $price, int $edition): int {
  exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,status,onsale_at,is_resaleable,resale_price_mode,created_at,updated_at)
       VALUES (1,'$name','https://qa.local/h2.png',$price,$edition,'onsale',DATE_SUB(NOW(),INTERVAL 1 HOUR),1,0,NOW(3),NOW(3))");
  return (int)v("SELECT id FROM nft_collectibles WHERE name='$name' ORDER BY id DESC LIMIT 1");
}
$cidW   = mkColl('H2-W', 1.00, 1000);
$cidU   = mkColl('H2-U', 1.00, 1000);
$cidAST = mkColl('H2-ASSET', 10.00, 100);
$cidMKT = mkColl('H2-MKT', 10.00, 100);
$cidBOX = mkColl('H2-BOX', 10.00, 100);
$cidMAT = mkColl('H2-MAT', 1.00, 100);
$cidRES = mkColl('H2-RES', 10.00, 100);
T('H2-0c 7 藏品种子', $cidW && $cidU && $cidAST && $cidMKT && $cidBOX && $cidMAT && $cidRES);

function mkAsset(int $uid, int $cid, string $tag): int {
  global $PDO;
  $serial = 'SNQ-' . $tag . '-' . sprintf('%06d', random_int(1, 999999));
  $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
    VALUES ($uid,$cid,'$serial','purchase',1.00,NOW(3),'held',NOW(3),NOW(3))");
  return (int)$PDO->lastInsertId();
}
/* 盲盒与奖池 */
exe("INSERT INTO nft_blind_boxes (collectible_id,is_openable,description) VALUES ($cidBOX,1,'H2测试盲盒')");
$bbId = (int)v("SELECT id FROM nft_blind_boxes WHERE collectible_id=$cidBOX ORDER BY id DESC LIMIT 1");
exe("INSERT INTO nft_blind_box_items (blind_box_id,prize_collectible_id,probability,quantity_limit,quantity_distributed)
     VALUES ($bbId,$cidU,1.0000,NULL,0)");
/* 合成：1×H2-MAT → H2-RES */
exe("INSERT INTO nft_synthesis_activities (type,title,rules,result_collectible_id,per_user_limit) VALUES ('permanent','H2合成','H2', $cidRES, 0)");
$synId = (int)v("SELECT id FROM nft_synthesis_activities WHERE title='H2合成' ORDER BY id DESC LIMIT 1");
exe("INSERT INTO nft_synthesis_materials (activity_id,collectible_id,count) VALUES ($synId,$cidMAT,1)");
$assetA1  = mkAsset($uids['15200000004'], $cidAST, 'A1');   // S：挂单/转赠
$assetA2  = mkAsset($uids['15200000004'], $cidMKT, 'A2');   // S：市场卖
$assetBox = mkAsset($uids['15200000006'], $cidBOX, 'BX');   // BB：开盒
$assetMat = mkAsset($uids['15200000007'], $cidMAT, 'MT');   // SYN：合成
T('H2-0d 盲盒/合成/资产种子', $bbId > 0 && $synId > 0 && $assetA1 > 0 && $assetA2 > 0 && $assetBox > 0 && $assetMat > 0);

echo "\n=== H2-1 同钱包并发支付（余额10 vs 30笔×1元）===\n";
$W = '15200000000'; $ordersW = [];
for ($i = 0; $i < 30; $i++) {
  $r = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$W],'b'=>['collectibleId'=>$cidW,'quantity'=>1,'paymentPassword'=>'Pay#2026']]])[0];
  if (($r['json']['code'] ?? -1) === 0) $ordersW[] = $r['json']['data']['orderNo'];
}
T('H2-1a 30 笔订单创建成功', count($ordersW) === 30, 'created=' . count($ordersW));
$pays = burst(array_map(fn ($no) => ['url'=>"$BASE/api/orders/$no/pay",'tok'=>$TOK[$W],
  'b'=>['orderNo'=>$no,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']], $ordersW));
$ok = 0; $noBal = 0;
foreach ($pays as $p) { $jc = $p['json']['code'] ?? -1; if ($jc === 0) $ok++; elseif ($jc === 4003) $noBal++; }
T('H2-1b 并发支付恰 10 成功 / 20 余额不足', $ok === 10 && $noBal === 20, "ok=$ok noBal=$noBal");
$wRow = q("SELECT balance,available,frozen FROM nft_wallets WHERE user_id={$uids[$W]}")[0];
T('H2-1c 余额精确归零且无负数', (float)$wRow['available'] === 0.0 && (float)$wRow['frozen'] === 0.0, json_encode($wRow));
$tx = q("SELECT amount,balance_after FROM nft_wallet_transactions WHERE user_id={$uids[$W]} AND trans_type='buy' ORDER BY id");
$sum = array_sum(array_map(fn ($t) => (float)$t['amount'], $tx));
$after = array_map(fn ($t) => (string)$t['balance_after'], $tx);
$chainOk = count($tx) === 10 && abs($sum - 10.0) < 0.001 && count(array_unique($after)) === 10
  && empty(array_diff($after, ['9.00','8.00','7.00','6.00','5.00','4.00','3.00','2.00','1.00','0.00']));
T('H2-1d 流水 10 笔/合计10元/balance_after 逐笔连续', $chainOk, 'rows=' . count($tx) . " sum=$sum uniq=" . count(array_unique($after)));
$stW = q("SELECT status,COUNT(*) c FROM nft_orders WHERE user_id={$uids[$W]} GROUP BY status");
$statW = []; foreach ($stW as $s) $statW[$s['status']] = (int)$s['c'];
T('H2-1e 订单终态 10 completed + 20 pending', ($statW['completed'] ?? 0) === 10 && ($statW['pending'] ?? 0) === 20, json_encode($statW));

echo "\n=== H2-2 双击/重复幂等 ===\n";
$U = '15200000001'; $uidU = $uids[$U];
/* a) pay 双击 */
$rc = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$U],'b'=>['collectibleId'=>$cidU,'quantity'=>1,'paymentPassword'=>'Pay#2026']]])[0];
$no1 = $rc['json']['data']['orderNo'] ?? '';
$dbl = burst([['url'=>"$BASE/api/orders/$no1/pay",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no1,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']],
              ['url'=>"$BASE/api/orders/$no1/pay",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no1,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']]]);
$okA = 0; $dup = 0;
foreach ($dbl as $d) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okA++; elseif ($jc === 4002) $dup++; }
$pay1 = (int)v("SELECT COUNT(*) FROM nft_payments p JOIN nft_orders o ON o.id=p.order_id WHERE o.order_no='$no1'");
$tx1  = (int)v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE biz_no='$no1'");
T('H2-2a pay 双击仅生效一次（1成功+1已处理，1流水1支付）', $okA === 1 && $dup === 1 && $pay1 === 1 && $tx1 === 1,
  "ok=$okA dup=$dup payments=$pay1 tx=$tx1");
/* b) cancel 双击 */
$rc = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$U],'b'=>['collectibleId'=>$cidU,'quantity'=>1,'paymentPassword'=>'Pay#2026']]])[0];
$no2 = $rc['json']['data']['orderNo'] ?? '';
$lockBefore = (int)v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidU");
$dbl = burst([['url'=>"$BASE/api/orders/$no2/cancel",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no2]],
              ['url'=>"$BASE/api/orders/$no2/cancel",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no2]]]);
$okB = 0; $rej = 0;
foreach ($dbl as $d) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okB++; elseif ($jc !== 0) $rej++; }
$lockAfter = (int)v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidU");
T('H2-2b cancel 双击仅释放一次锁定量', $okB === 1 && $rej === 1 && $lockAfter === $lockBefore - 1 && $lockAfter >= 0, "ok=$okB rej=$rej locked $lockBefore->$lockAfter");
/* c) pay vs cancel 对打 */
$rc = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$U],'b'=>['collectibleId'=>$cidU,'quantity'=>1,'paymentPassword'=>'Pay#2026']]])[0];
$no3 = $rc['json']['data']['orderNo'] ?? '';
$sold0 = (int)v("SELECT sold FROM nft_collectibles WHERE id=$cidU");
$dbl = burst([['url'=>"$BASE/api/orders/$no3/pay",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no3,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']],
              ['url'=>"$BASE/api/orders/$no3/cancel",'tok'=>$TOK[$U],'b'=>['orderNo'=>$no3]]]);
$okP = 0; $okC = 0;
foreach ($dbl as $d) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) { (isset($d['json']['data']['status'])) ? $okP++ : $okC++; } }
$ord3 = v("SELECT status FROM nft_orders WHERE order_no='$no3'");
$sold1 = (int)v("SELECT sold FROM nft_collectibles WHERE id=$cidU");
$lock1 = (int)v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidU");
$finalOk = ($ord3 === 'completed' && $sold1 === $sold0 + 1) || ($ord3 === 'cancelled' && $sold1 === $sold0);
T('H2-2c pay/cancel 对打恰好一方生效（状态互斥）', ($okP + $okC) >= 1 && $finalOk && $lock1 === $lockAfter,
  "pay=$okP cancel=$okC final=$ord3 sold $sold0->$sold1");
/* d) 签到双击 */
$pts0 = (float)v("SELECT points FROM nft_wallets WHERE user_id=$uidU");
$dbl = burst([['url'=>"$BASE/api/check-in",'tok'=>$TOK[$U],'b'=>[]],
              ['url'=>"$BASE/api/check-in",'tok'=>$TOK[$U],'b'=>[]]]);
$today = date('Y-m-d'); // 应用按 PHP 时区(Asia/Shanghai)写 check_in_date，MySQL CURDATE() 为 UTC 会错位
$recU = (int)v("SELECT COUNT(*) FROM nft_check_in_records WHERE user_id=$uidU AND check_in_date='$today'");
$pts1 = (float)v("SELECT points FROM nft_wallets WHERE user_id=$uidU");
$txReward = (int)v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id=$uidU AND trans_type='reward' AND title LIKE '%签到%'");
T('H2-2d 签到双击仅一条记录/一份奖励', $recU === 1 && $txReward <= 1 && abs(($pts1 - $pts0) - 5.0 * $txReward) < 0.001,
  "records=$recU rewardTx=$txReward points {$pts0}->{$pts1}");

/* e) 免费抽奖 H2-D1 探针（修复前预期 FAIL） */
$LD1 = '15200000002';
$d1 = burst([['url'=>"$BASE/api/lucky-draw/draw",'tok'=>$TOK[$LD1],'b'=>[]]])[0];
$d2 = burst([['url'=>"$BASE/api/lucky-draw/draw",'tok'=>$TOK[$LD1],'b'=>[]]])[0];
$recLD1 = (int)v("SELECT COUNT(*) FROM nft_lucky_draw_records WHERE user_id={$uids[$LD1]}");
T('H2-2e1 免费抽仅首次放行（顺序第二次必须拒绝）', ($d1['json']['code'] ?? -1) === 0 && ($d2['json']['code'] ?? -1) !== 0 && $recLD1 === 1,
  'draw1=' . ($d1['json']['code'] ?? '?') . ' draw2=' . ($d2['json']['code'] ?? '?') . " records=$recLD1 【修复前 FAIL=缺陷H2-D1】");
$LD2 = '15200000003';
$dbl = burst([['url'=>"$BASE/api/lucky-draw/draw",'tok'=>$TOK[$LD2],'b'=>[]],
              ['url'=>"$BASE/api/lucky-draw/draw",'tok'=>$TOK[$LD2],'b'=>[]]]);
$okL = 0; foreach ($dbl as $d) if (($d['json']['code'] ?? -1) === 0) $okL++;
$recLD2 = (int)v("SELECT COUNT(*) FROM nft_lucky_draw_records WHERE user_id={$uids[$LD2]}");
T('H2-2e2 免费抽并发双击恰 1 次', $okL === 1 && $recLD2 === 1, "ok=$okL records=$recLD2 【修复前 FAIL=缺陷H2-D1】");

/* f) 开盒双击 */
$dbl = burst([['url'=>"$BASE/api/blind-boxes/open",'tok'=>$TOK['15200000006'],'b'=>['userCollectibleId'=>$assetBox,'paymentPassword'=>'Pay#2026']],
              ['url'=>"$BASE/api/blind-boxes/open",'tok'=>$TOK['15200000006'],'b'=>['userCollectibleId'=>$assetBox,'paymentPassword'=>'Pay#2026']]]);
$okBB = 0; $rejBB = 0;
foreach ($dbl as $d) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okBB++; else $rejBB++; }
$dist = (int)v("SELECT quantity_distributed FROM nft_blind_box_items WHERE blind_box_id=$bbId");
$boxSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetBox");
$prizeN = (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$uids['15200000006']} AND source='blindbox'");
T('H2-2f 开盒双击恰开一次', $okBB === 1 && $rejBB === 1 && $dist === 1 && $prizeN === 1 && $boxSt === 'consumed',
  "ok=$okBB rej=$rejBB dist=$dist prize=$prizeN boxStatus=$boxSt");

/* g) 合成双击 */
$dbl = burst([['url'=>"$BASE/api/synthesis/submit",'tok'=>$TOK['15200000007'],'b'=>['activityId'=>$synId]],
              ['url'=>"$BASE/api/synthesis/submit",'tok'=>$TOK['15200000007'],'b'=>['activityId'=>$synId]]]);
$okS = 0; $rejS = 0;
foreach ($dbl as $d) { $jc = $d['json']['code'] ?? -1; if ($jc === 0) $okS++; else $rejS++; }
$recS = (int)v("SELECT COUNT(*) FROM nft_synthesis_records WHERE user_id={$uids['15200000007']}");
$resN = (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$uids['15200000007']} AND source='synthesis'");
$matSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetMat");
T('H2-2g 合成双击恰合成一次', $okS === 1 && $rejS === 1 && $recS === 1 && $resN === 1 && $matSt === 'consumed',
  "ok=$okS rej=$rejS records=$recS result=$resN matStatus=$matSt");

echo "\n=== H2-3 同资产并发挂单/转赠 ===\n";
$S = '15200000004';
$reqs = [];
for ($i = 0; $i < 30; $i++) $reqs[] = ['url'=>"$BASE/api/resale/listings",'tok'=>$TOK[$S],'b'=>['userCollectibleId'=>$assetA1,'price'=>10.00,'paymentPassword'=>'Pay#2026']];
$rs = burst($reqs);
$okR = 0; foreach ($rs as $r) if (($r['json']['code'] ?? -1) === 0) $okR++;
$lst = (int)v("SELECT COUNT(*) FROM nft_resale_listings WHERE user_collectible_id=$assetA1 AND status='selling'");
$astSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetA1");
T('H2-3a 同资产 30 并发挂单恰 1 成功', $okR === 1 && $lst === 1 && $astSt === 'consigned', "ok=$okR listings=$lst asset=$astSt");
/* 取消挂单 → 转赠 30 并发 */
$lstId = (int)v("SELECT id FROM nft_resale_listings WHERE user_collectible_id=$assetA1 AND status='selling'");
burst([['url'=>"$BASE/api/resale/listings/$lstId/cancel",'tok'=>$TOK[$S],'b'=>[]]]);
$astSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetA1");
$reqs = [];
for ($i = 0; $i < 30; $i++) $reqs[] = ['url'=>"$BASE/api/transfers",'tok'=>$TOK[$S],'b'=>['userCollectibleId'=>$assetA1,'toPhone'=>'15200000001','paymentPassword'=>'Pay#2026']];
$rs = burst($reqs);
$okT = 0; foreach ($rs as $r) if (($r['json']['code'] ?? -1) === 0) $okT++;
$trf = (int)v("SELECT COUNT(*) FROM nft_transfers WHERE user_collectible_id=$assetA1 AND status='pending'");
$astSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetA1");
T('H2-3b 同资产 30 并发转赠恰 1 成功', $okT === 1 && $trf === 1 && $astSt === 'frozen', "ok=$okT transfers=$trf asset=$astSt");

echo "\n=== H2-4 市场单中途失败全回滚 ===\n";
$M = '15200000005';
$rl = burst([['url'=>"$BASE/api/resale/listings",'tok'=>$TOK[$S],'b'=>['userCollectibleId'=>$assetA2,'price'=>10.00,'paymentPassword'=>'Pay#2026']]])[0];
$listingId = (int)($rl['json']['data']['listingId'] ?? 0);
T('H2-4a 卖家挂单成功', $listingId > 0 && ($rl['json']['code'] ?? -1) === 0, "listingId=$listingId");
$mo = burst([['url'=>"$BASE/api/orders",'tok'=>$TOK[$M],'b'=>['resaleListingId'=>$listingId,'paymentPassword'=>'Pay#2026']]])[0];
$marketOrderNo = $mo['json']['data']['orderNo'] ?? '';
T('H2-4b 买家创建市场订单', ($mo['json']['code'] ?? -1) === 0 && $marketOrderNo !== '', json_encode($mo['json']));
$balM0 = (float)v("SELECT available FROM nft_wallets WHERE user_id={$uids[$M]}");
/* 破坏：支付前卖家资产被转移走（模拟资产异常） */
exe("UPDATE nft_user_collectibles SET user_id={$uids['15200000001']} WHERE id=$assetA2");
$pr = burst([['url'=>"$BASE/api/orders/$marketOrderNo/pay",'tok'=>$TOK[$M],'b'=>['orderNo'=>$marketOrderNo,'paymentMethod'=>'balance','paymentPassword'=>'Pay#2026']]])[0];
$payFailCode = $pr['json']['code'] ?? -1;
$balM1 = (float)v("SELECT available FROM nft_wallets WHERE user_id={$uids[$M]}");
$payN = (int)v("SELECT COUNT(*) FROM nft_payments p JOIN nft_orders o ON o.id=p.order_id WHERE o.order_no='$marketOrderNo'");
$ordSt = v("SELECT status FROM nft_orders WHERE order_no='$marketOrderNo'");
T('H2-4c 中途失败拒绝（3001）且钱包零变动', $payFailCode === 3001 && abs($balM1 - $balM0) < 0.001,
  "code=$payFailCode wallet {$balM0}->{$balM1}");
T('H2-4d 无支付记录且订单保持 pending', $payN === 0 && $ordSt === 'pending', "payments=$payN status=$ordSt");
/* 复原：资产归还卖家 → 取消订单恢复挂单 → 取消挂单 */
exe("UPDATE nft_user_collectibles SET user_id={$uids[$S]} WHERE id=$assetA2");
burst([['url'=>"$BASE/api/orders/$marketOrderNo/cancel",'tok'=>$TOK[$M],'b'=>['orderNo'=>$marketOrderNo]]]);
burst([['url'=>"$BASE/api/resale/listings/$listingId/cancel",'tok'=>$TOK[$S],'b'=>[]]]);
$astSt = v("SELECT status FROM nft_user_collectibles WHERE id=$assetA2");
T('H2-4e 环境复原（资产回 held）', $astSt === 'held', "asset=$astSt");

echo "\n==== H2 结果: PASS $pass / FAIL $fail ====\n";
if ($fails) { echo "失败项:\n" . implode("\n", $fails) . "\n"; exit(1); }
exit(0);

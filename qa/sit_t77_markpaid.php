<?php
/** F7-7 后台「标记支付」mark-paid + 支付渠道枚举对齐 集成测试
 *  覆盖：
 *    7.7.0 环境与夹具
 *    7.7.1 nft_payments.payment_method 枚举与 PaymentService::CODES / SystemController::CHANNEL_CODES 对齐
 *    7.7.2 渠道白名单校验（非法渠道直接 4220，不落库）
 *    7.7.3 参数与状态校验（id 非法、订单不存在/已处理）
 *    7.7.4 库存守恒守卫：锁定量已被释放时干净 4220，不回吐 SQL 原文、整事务回滚
 *    7.7.5 正向真实链路：C 端下单锁库存 → 后台标记 huifu 支付 → 库存结转/持仓/流水/订单状态
 *    7.7.6 重复标记幂等
 *    7.7.7 balance 渠道：余额充足扣款开流水；余额不足拒绝且不产生任何副作用
 *    7.7.8 市场模式挂单缺失时 4220（而非崩溃）
 *    7.7.9 C 端第三方渠道支付同样受枚举对齐保护（渠道启停联动）
 *    7.7.10 权限：order:manage 白名单角色可用、其余 4003、无 token 4001
 *    7.7.99 清理与配置还原
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = getenv('QA_BASE') ?: 'http://127.0.0.1:8080';
define('QA_PDO_ERRMODE', PDO::ERRMODE_EXCEPTION);
require __DIR__ . '/bootstrap_db.php';
$pass = 0; $fail = 0; $fails = [];
function T($n, $c, $d = '') { global $pass, $fail, $fails; $c ? $pass++ : $fail++; if (!$c) $fails[] = $n; echo ($c ? "  PASS " : "  FAIL ") . $n . ($d ? " | $d" : "") . "\n"; }
function http($m, $u, $b = null, $t = null) { global $BASE; $ch = curl_init($BASE . $u); $h = ['Content-Type: application/json']; if ($t) $h[] = "Authorization: Bearer $t";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
    $raw = curl_exec($ch); return json_decode($raw, true) ?: ['code' => -2, 'message' => 'BADJSON']; }
function v($sql) { global $PDO; $r = $PDO->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function exe($sql) { global $PDO; return $PDO->exec($sql); }
function login($u, $p) { return (string) (http('POST', '/admin/auth/login', ['username' => $u, 'password' => $p])['data']['token'] ?? ''); }
function mk($uid, $phone) { // C 端 JWT 同源铸造（与 app/service/JwtService.php 一致：HS256 + .env SECRET）
    $src = (string) file_get_contents(__DIR__ . '/../sinan-nft-backend/.env');
    preg_match('/^SECRET\s*=\s*(.+)$/m', $src, $m); $s = trim($m[1] ?? '');
    $b64 = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $h = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $p = $b64(json_encode(['iss' => 'sinan-nft-audience', 'aud' => 'sinan-nft-client', 'iat' => time(), 'exp' => time() + 86400, 'sub' => $uid, 'phone' => $phone]));
    return "$h.$p." . $b64(hash_hmac('sha256', "$h.$p", $s, true));
}

echo "=== 7.7.0 环境与夹具 ===\n";
$tokSuper = login('admin', 'admin123');
$tokFin   = login('finance_admin', 'RoleTest#2026');
$tokRisk  = login('risk_admin', 'RoleTest#2026');
T('7.7.0a 三角色登录', $tokSuper && $tokFin && $tokRisk);

$PHONE = '15800007701';
$PWD   = 'Pay#2026';
$hash  = password_hash($PWD, PASSWORD_BCRYPT);
$cfgOld = (string) v("SELECT config_value FROM nft_system_configs WHERE config_key='purchase_limit_per_user'");
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '1580000%'");
foreach (['nft_wallet_transactions', 'nft_payments', 'nft_orders', 'nft_wallets'] as $tb) {
    exe("DELETE x FROM $tb x JOIN nft_users u ON u.id=x.user_id WHERE u.phone LIKE '1580000%'");
}
exe("DELETE FROM nft_users WHERE phone LIKE '1580000%'");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'T77-%'");
exe("INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,transaction_password,created_at,updated_at)
     VALUES ('$PHONE','T77探针','','T7700001','T7700001',1,'$hash',NOW(3),NOW(3))");
$uid = (int) v("SELECT id FROM nft_users WHERE phone='$PHONE'");
exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points) VALUES ($uid,1000.00,1000.00,0.00,0.00)");
qa_seed_wallet_ledger($PDO, [$uid]);
exe("UPDATE nft_system_configs SET config_value='100000' WHERE config_key='purchase_limit_per_user'");
$secretOk = (bool) preg_match('/^SECRET\s*=\s*\S/m', (string) file_get_contents(__DIR__ . '/../sinan-nft-backend/.env'));
$tok = mk($uid, $PHONE);
T('7.7.0b 种子用户/钱包/交易密码/JWT 密钥可读', $uid > 0 && strlen($tok) > 60 && $secretOk, "uid=$uid");

function mkColl(string $name, int $edition, float $price): int {
    exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,status,onsale_at,created_at,updated_at)
         VALUES (1,'$name','https://qa.local/t77.png',$price,$edition,'onsale',DATE_SUB(NOW(),INTERVAL 1 HOUR),NOW(3),NOW(3))");
    return (int) v("SELECT id FROM nft_collectibles WHERE name='$name'");
}
/** 走真实 C 端下单，返回 [orderNo, orderId] */
function placeOrder(int $cid, int $qty) {
    global $tok, $BASE, $PWD;
    $r = http('POST', '/api/orders', ['collectibleId' => $cid, 'quantity' => $qty, 'paymentPassword' => $PWD], $tok);
    $no = (string) ($r['data']['orderNo'] ?? '');
    return [$no, $no === '' ? 0 : (int) v('SELECT id FROM nft_orders WHERE order_no=' . dbq($no))];
}
function dbq($s) { global $PDO; return $PDO->quote((string) $s); }

echo "\n=== 7.7.1 payments.payment_method 枚举与渠道白名单对齐 ===\n";
$enum = (string) v("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=SCHEMA() AND TABLE_NAME='nft_payments' AND COLUMN_NAME='payment_method'");
$missing = [];
foreach (['balance', 'alipay', 'wechat', 'huifu', 'unionpay', 'yeepay'] as $c) if (!str_contains($enum, "'$c'")) $missing[] = $c;
T('7.7.1a 枚举含全部 6 个渠道编码', !$missing, '缺少=' . implode(',', $missing) . " 列定义=$enum");
$strict = str_contains((string) v("SELECT @@SESSION.sql_mode"), 'STRICT_TRANS_TABLES');
T('7.7.1b sql_mode 为严格模式（枚举不匹配会 1265 而非静默截断）', $strict);
$cidP = mkColl('T77-P', 20, 0.01); // 枚举探针专用藏品（payments.order_id 有 FK + uk_order 唯一，必须每渠道配一张真单）
foreach (['huifu', 'unionpay', 'yeepay'] as $c) {
    exe("INSERT INTO nft_orders (order_no,user_id,collectible_id,quantity,unit_price,total_price,status,source,expires_at,created_at,updated_at)
         VALUES ('T77P$c', $uid, $cidP, 1, 0.01, 0.01, 'pending', 'release', DATE_ADD(NOW(3), INTERVAL 30 MINUTE), NOW(3), NOW(3))");
    $oidP = (int) v("SELECT id FROM nft_orders WHERE order_no='T77P$c'");
    exe("INSERT INTO nft_payments (order_id,user_id,amount,payment_method,status,created_at,updated_at)
         VALUES ($oidP,$uid,0.01,'$c','success',NOW(3),NOW(3))");
    $back = (string) v("SELECT payment_method FROM nft_payments WHERE order_id=$oidP");
    T("7.7.1c 直插 $c 不被截断（严格模式无 1265）", $back === $c, "读回=$back");
    exe("DELETE FROM nft_payments WHERE order_id=$oidP");
    exe("DELETE FROM nft_orders WHERE id=$oidP");
}

echo "\n=== 7.7.2/7.7.3 入参校验 ===\n";
$cidA = mkColl('T77-A', 20, 0.01);
[$noA, $oidA] = placeOrder($cidA, 2);
T('7.7.2a 下单成功（后续用例的主订单）', $oidA > 0, "orderNo=$noA");
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'mock'], $tokSuper);
T('7.7.2b 非白名单渠道 4220', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 40));
T('7.7.2c 非法渠道未落任何支付流水', (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidA") === 0);
$r = http('POST', '/admin/orders/abc/mark-paid', ['payment_method' => 'alipay'], $tokSuper);
T('7.7.3a id 非正整数 4220', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-'));
$r = http('POST', '/admin/orders/999999999/mark-paid', ['payment_method' => 'alipay'], $tokSuper);
T('7.7.3b 订单不存在 4220', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-'));

echo "\n=== 7.7.4 库存守恒守卫（锁定量已被释放）===\n";
$noZ = 'T77Z' . time() . random_int(100, 999);
$cidZ = mkColl('T77-Z', 20, 0.01);
exe("INSERT INTO nft_orders (order_no,user_id,collectible_id,quantity,unit_price,total_price,status,source,expires_at,created_at,updated_at)
     VALUES ('$noZ', $uid, $cidZ, 1, 1, 1, 'pending', 'release', DATE_ADD(NOW(3), INTERVAL 30 MINUTE), NOW(3), NOW(3))");
$oidZ = (int) v("SELECT id FROM nft_orders WHERE order_no='$noZ'");
$lockedZ0 = (int) v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidZ");
T('7.7.4a 夹具：订单 pending 但藏品锁定量为 0', $lockedZ0 === 0, "locked=$lockedZ0");
$r = http('POST', "/admin/orders/$oidZ/mark-paid", ['payment_method' => 'huifu'], $tokSuper);
$msg = (string) ($r['message'] ?? '');
T('7.7.4b 返回业务 4220 而非 5000', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-') . " msg=" . mb_substr($msg, 0, 40));
T('7.7.4c 错误消息不回吐 SQL 原文（1690 已消除）', !str_contains($msg, 'SQLSTATE') && !str_contains($msg, 'BIGINT UNSIGNED'), 'msg=' . mb_substr($msg, 0, 60));
T('7.7.4d 整事务回滚：无流水、订单仍 pending、锁定量未变负',
    (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidZ") === 0
    && (string) v("SELECT status FROM nft_orders WHERE id=$oidZ") === 'pending'
    && (int) v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidZ") === 0);

echo "\n=== 7.7.5 正向链路：huifu 渠道标记支付 ===\n";
$b = $PDO->query("SELECT sold,locked_quantity,circulate FROM nft_collectibles WHERE id=$cidA")->fetch(PDO::FETCH_ASSOC);
T('7.7.5a 下单后 locked=2、sold=0', (int) $b['locked_quantity'] === 2 && (int) $b['sold'] === 0, "sold={$b['sold']} locked={$b['locked_quantity']}");
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'huifu', 'transaction_no' => 'T77-HF-' . time()], $tokSuper);
T('7.7.5b 标记支付成功', ($r['code'] ?? -1) === 200, 'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 40));
$a = $PDO->query("SELECT sold,locked_quantity,circulate FROM nft_collectibles WHERE id=$cidA")->fetch(PDO::FETCH_ASSOC);
T('7.7.5c 库存结转守恒：sold/circulate 各 +2、locked 归零',
    (int) $a['sold'] === 2 && (int) $a['locked_quantity'] === 0 && (int) $a['circulate'] === 2,
    "sold={$a['sold']} locked={$a['locked_quantity']} circulate={$a['circulate']}");
$payA = $PDO->query("SELECT payment_method,amount,status FROM nft_payments WHERE order_id=$oidA")->fetchAll(PDO::FETCH_ASSOC);
T('7.7.5d 支付流水恰 1 条且记为 huifu 成功', count($payA) === 1 && $payA[0]['payment_method'] === 'huifu' && $payA[0]['status'] === 'success', json_encode($payA, JSON_UNESCAPED_UNICODE));
$ser = $PDO->query("SELECT serial,status FROM nft_user_collectibles WHERE order_id=$oidA ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
T('7.7.5e 生成 2 份 held 持仓且编号取自 sold 序号',
    count($ser) === 2 && $ser[0]['status'] === 'held' && $ser[1]['status'] === 'held'
    && str_ends_with($ser[0]['serial'], '-0001') && str_ends_with($ser[1]['serial'], '-0002'), json_encode($ser));
T('7.7.5f 订单置为 completed 且 paid_at 落库',
    (string) v("SELECT status FROM nft_orders WHERE id=$oidA") === 'completed' && v("SELECT paid_at FROM nft_orders WHERE id=$oidA") !== null);

echo "\n=== 7.7.6 重复标记幂等 ===\n";
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'huifu'], $tokSuper);
T('7.7.6a 已完成订单再次标记 4220', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-'));
T('7.7.6b 无重复结转：流水仍 1 条、sold 仍 2、持仓仍 2',
    (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidA") === 1
    && (int) v("SELECT sold FROM nft_collectibles WHERE id=$cidA") === 2
    && (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$oidA") === 2);

echo "\n=== 7.7.7 balance 渠道 ===\n";
$cidB = mkColl('T77-B', 20, 0.02);
[$noB, $oidB] = placeOrder($cidB, 1);
$balBefore = (float) v("SELECT available FROM nft_wallets WHERE user_id=$uid");
$r = http('POST', "/admin/orders/$oidB/mark-paid", ['payment_method' => 'balance'], $tokSuper);
T('7.7.7a 余额充足时标记余额支付成功', ($r['code'] ?? -1) === 200, 'code=' . ($r['code'] ?? '-'));
$balAfter = (float) v("SELECT available FROM nft_wallets WHERE user_id=$uid");
T('7.7.7b 钱包扣款 0.02', abs(($balBefore - $balAfter) - 0.02) < 0.001, "before=$balBefore after=$balAfter");
$bt = $PDO->query("SELECT trans_type,amount,balance_after FROM nft_wallet_transactions WHERE biz_no=" . dbq($noB))->fetchAll(PDO::FETCH_ASSOC);
T('7.7.7c 生成 buy 流水且 balance_after 与实际余额一致',
    count($bt) === 1 && $bt[0]['trans_type'] === 'buy' && abs((float) $bt[0]['balance_after'] - $balAfter) < 0.001, json_encode($bt));
T('7.7.7d 支付流水记为 balance', (string) v("SELECT payment_method FROM nft_payments WHERE order_id=$oidB") === 'balance');

$cidC = mkColl('T77-C', 20, 0.02);
[$noC, $oidC] = placeOrder($cidC, 1);
exe("UPDATE nft_wallets SET balance=0.00, available=0.00 WHERE user_id=$uid");
exe("DELETE FROM nft_wallet_transactions WHERE user_id=$uid AND trans_type='recharge' AND title='测试资金开账'");
qa_seed_wallet_ledger($PDO, [$uid]); // 清零后重新开账，保持资金恒等式
$r = http('POST', "/admin/orders/$oidC/mark-paid", ['payment_method' => 'balance'], $tokSuper);
T('7.7.7e 余额不足 4220', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 40));
T('7.7.7f 拒绝后无副作用：无流水、订单仍 pending、库存未结转',
    (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidC") === 0
    && (string) v("SELECT status FROM nft_orders WHERE id=$oidC") === 'pending'
    && (int) v("SELECT locked_quantity FROM nft_collectibles WHERE id=$cidC") === 1);

echo "\n=== 7.7.8 市场模式（挂单缺失）===\n";
exe("INSERT INTO nft_orders (order_no,user_id,collectible_id,quantity,unit_price,total_price,status,source,resale_listing_id,expires_at,created_at,updated_at)
     VALUES ('T77M$oidA', $uid, $cidA, 1, 1, 1, 'pending', 'market', NULL, DATE_ADD(NOW(3), INTERVAL 30 MINUTE), NOW(3), NOW(3))");
$oidM = (int) v("SELECT id FROM nft_orders WHERE order_no='T77M$oidA'");
$r = http('POST', "/admin/orders/$oidM/mark-paid", ['payment_method' => 'alipay'], $tokSuper);
T('7.7.8a 挂单缺失返回业务 4220（无异常吞没）', ($r['code'] ?? -1) === 4220, 'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 40));
T('7.7.8b 挂单校验失败一并回滚支付流水', (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidM") === 0);

echo "\n=== 7.7.9 C 端第三方渠道支付（枚举同源）===\n";
$chRow = $PDO->query("SELECT id,status,config FROM nft_payment_channels WHERE channel_code='huifu'")->fetch(PDO::FETCH_ASSOC);
$cidD = mkColl('T77-D', 20, 0.01);
$cidE = mkColl('T77-E', 20, 0.01);
$r = http('PUT', "/admin/system/payment-channels/{$chRow['id']}", ['status' => 1, 'config' => ['app_id' => 'T77', 'gateway' => 'https://qa.local/gw']], $tokSuper);
T('7.7.9a 后台启用 huifu 渠道', ($r['code'] ?? -1) === 200, 'code=' . ($r['code'] ?? '-'));
exe("UPDATE nft_wallets SET balance=0.00, available=0.00 WHERE user_id=$uid"); // 逼走第三方分支
[$noD, $oidD] = placeOrder($cidD, 1);
$r = http('POST', "/api/orders/$noD/pay", ['orderNo' => $noD, 'paymentMethod' => 'huifu', 'paymentPassword' => $PWD], $tok);
T('7.7.9b C 端 huifu 支付成功（不再 1265 截断）', ($r['code'] ?? -1) === 0, 'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 50));
T('7.7.9c C 端流水记为 huifu 且资产交割', (string) v("SELECT payment_method FROM nft_payments WHERE order_id=$oidD") === 'huifu'
    && (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$oidD AND status='held'") === 1);
[$noE, $oidE] = placeOrder($cidE, 1);
$r = http('POST', "/api/orders/$noE/pay", ['orderNo' => $noE, 'paymentMethod' => 'yeepay', 'paymentPassword' => $PWD], $tok);
T('7.7.9d 未启用渠道在写库前即被拒绝', ($r['code'] ?? -1) === 5001 && (int) v("SELECT COUNT(*) FROM nft_payments WHERE order_id=$oidE") === 0,
    'code=' . ($r['code'] ?? '-') . ' msg=' . mb_substr((string) ($r['message'] ?? ''), 0, 40));
$r = http('GET', '/api/payments/available', null, $tok);
$codes = array_column((array) ($r['data'] ?? []), 'method');
T('7.7.9e C 端支付方式列表跟随后台启停', in_array('huifu', $codes, true) && !in_array('yeepay', $codes, true), 'available=' . implode(',', $codes));

echo "\n=== 7.7.10 权限 ===\n";
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'alipay'], $tokRisk);
T('7.7.10a 风控（无 order:manage）4003', ($r['code'] ?? -1) === 4003, 'code=' . ($r['code'] ?? '-'));
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'alipay']);
T('7.7.10b 无 token 4001', ($r['code'] ?? -1) === 4001, 'code=' . ($r['code'] ?? '-'));
$r = http('POST', "/admin/orders/$oidA/mark-paid", ['payment_method' => 'alipay'], $tokFin);
T('7.7.10c 财务（有 order:manage）通过鉴权', ($r['code'] ?? -1) !== 4003, 'code=' . ($r['code'] ?? '-'));

echo "\n=== 7.7.99 清理与配置还原 ===\n";
exe("UPDATE nft_payment_channels SET status={$chRow['status']}, config=" . ($chRow['config'] === null ? 'NULL' : dbq($chRow['config'])) . " WHERE id={$chRow['id']}");
exe("UPDATE nft_system_configs SET config_value=" . dbq($cfgOld) . " WHERE config_key='purchase_limit_per_user'");
$ph = "'1580000%'";
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE $ph");
exe("DELETE wt FROM nft_wallet_transactions wt JOIN nft_users u ON u.id=wt.user_id WHERE u.phone LIKE $ph");
exe("DELETE a FROM nft_approval_requests a JOIN nft_refunds r ON r.id=a.target_id AND a.target_type='refund' JOIN nft_orders o ON o.id=r.order_id JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE $ph");
exe("DELETE r FROM nft_refunds r JOIN nft_orders o ON o.id=r.order_id JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE $ph");
exe("DELETE p FROM nft_payments p JOIN nft_orders o ON o.id=p.order_id JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE $ph");
exe("DELETE o FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE $ph");
exe("DELETE FROM nft_payments WHERE order_id IN (SELECT id FROM nft_orders WHERE order_no LIKE 'T77P%')");
exe("DELETE FROM nft_orders WHERE order_no LIKE 'T77P%'");
exe("DELETE w FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE $ph");
exe("DELETE FROM nft_users WHERE phone LIKE $ph");
exe("DELETE FROM nft_user_collectibles WHERE collectible_id IN ($cidA,$cidB,$cidC,$cidD,$cidE,$cidZ,$cidP)");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'T77-%'");
$left = (int) v("SELECT COUNT(*) FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE '1580000%'")
      + (int) v("SELECT COUNT(*) FROM nft_users WHERE phone LIKE '1580000%'")
      + (int) v("SELECT COUNT(*) FROM nft_collectibles WHERE name LIKE 'T77-%'")
      + (int) v("SELECT COUNT(*) FROM nft_orders WHERE order_no LIKE 'T77%'");
T('7.7.99 夹具与配置已还原', $left === 0, "残留=$left  purchase_limit_per_user=" . v("SELECT config_value FROM nft_system_configs WHERE config_key='purchase_limit_per_user'"));

echo "\n========== 汇总：PASS=$pass FAIL=$fail ==========\n";
if ($fails) { echo "失败清单：\n" . implode("\n", $fails) . "\n"; }

<?php
/** 市场挂单「锁定中」展示回归：
 *   1) 下单未付款 → 挂单仍在池中但 locked=true，lockedCount=1，成交动态不含它
 *      同一买家再下单被拒 3005（待支付订单未处理完不得再次锁定），他人买该单被拒 1002
 *   2) 取消订单   → 挂单恢复 selling，locked=false，lockedCount=0
 *   3) 下单并支付 → 挂单从池中消失，成交动态出现
 *  自清洁：手机号段 139000092xx、藏品段 96xx，可重复执行
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = getenv('QA_BASE') ?: 'http://127.0.0.1:8080';
define('QA_PDO_ERRMODE', PDO::ERRMODE_WARNING);
require __DIR__ . '/bootstrap_db.php';
$pass = 0; $fail = 0;
function T($n, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? "  PASS " : "  FAIL ") . $n . ($d ? " | $d" : "") . "\n"; }
function http($m, $u, $b = null, $t = null) { global $BASE; $ch = curl_init($BASE . $u); $h = ['Content-Type: application/json']; if ($t) $h[] = "Authorization: Bearer $t";
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: [])]);
  $raw = curl_exec($ch); curl_close($ch); return json_decode($raw, true) ?: ['code' => -2, 'message' => 'BADJSON']; }
function v($sql) { global $PDO; $r = $PDO->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function exe($sql) { global $PDO; return $PDO->exec($sql); }
function regUser($phone, $nick) { global $PDO, $BASE;
  exe("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','" . password_hash('654321', PASSWORD_BCRYPT) . "','" . date('Y-m-d H:i:s', time() + 600) . "',NOW(),'127.0.0.1',NOW())");
  $j = http('POST', '/api/auth/register', ['phone' => $phone, 'code' => '654321', 'nickname' => $nick, 'password' => 'Pass#999']);
  $uid = ($j['code'] ?? -1) === 0 ? (int)v("SELECT id FROM nft_users WHERE phone='$phone'") : 0;
  return [$uid, (string)($j['data']['token'] ?? '')];
}
function seedHolder($uid, $cid) { global $PDO; $s = 'SN-' . $cid . '-L' . substr((string)microtime(true), -6) . rand(100, 999);
  exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at) VALUES ($uid,$cid,'$s','purchase',0,NOW(),'held',NOW(),NOW())");
  return (int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
}
function poolRow($lid) { global $BASE;
  $r = http('GET', '/api/resale/listings?collectibleId=9611&page=1&pageSize=50');
  $row = null; foreach (($r['data']['list'] ?? []) as $it) { if ((int)($it['listingId'] ?? 0) === $lid) { $row = $it; break; } }
  return [$row, (int)($r['data']['lockedCount'] ?? -1), (float)($r['data']['floorPrice'] ?? 0)];
}
function inHistory($lid) { $r = http('GET', '/api/resale/history?collectibleId=9611&page=1&pageSize=50');
  foreach (($r['data']['list'] ?? $r['data'] ?? []) as $it) { if ((int)($it['id'] ?? 0) === $lid) return true; } return false; }
function q_ids() { global $PDO; return array_map('intval', $PDO->query("SELECT id FROM nft_users WHERE phone LIKE '139000092%'")->fetchAll(PDO::FETCH_COLUMN) ?: []); }

echo "========== 0. 造数 ==========\n";
$old = q_ids();
if ($old) { $ids = implode(',', $old);
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach (['nft_wallets', 'nft_wallet_transactions', 'nft_user_collectibles', 'nft_orders', 'nft_payments', 'nft_buy_requests'] as $tb) { exe("DELETE FROM $tb WHERE user_id IN ($ids)"); }
  exe("DELETE FROM nft_transfers WHERE from_user_id IN ($ids) OR to_user_id IN ($ids)");
  exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($ids)");
  exe("DELETE FROM nft_users WHERE id IN ($ids)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
exe("DELETE FROM nft_user_collectibles WHERE collectible_id=9611");
exe("DELETE FROM nft_resale_listings WHERE collectible_id=9611");
exe("DELETE FROM nft_collectibles WHERE id=9611");
exe("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at)
  VALUES (9611,1,'锁定回归藏品','','',100,1000,0,0,0,10,1,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW())");
[$uidA, $tokA] = regUser('13900009201', '锁定甲');
[$uidB, $tokB] = regUser('13900009202', '锁定乙');
[$uidC, $tokC] = regUser('13900009203', '锁定丙');
$th = password_hash('Trade#2026', PASSWORD_BCRYPT);
exe("UPDATE nft_users SET is_realname=1, realname_status=2, transaction_password='$th' WHERE id IN ($uidA,$uidB,$uidC)");
foreach ([$uidA, $uidB, $uidC] as $u) { exe("DELETE FROM nft_wallets WHERE user_id=$u");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points,created_at,updated_at) VALUES ($u,100000,100000,0,0,NOW(),NOW())");
  exe("INSERT INTO nft_wallet_transactions (user_id,trans_type,title,direction,amount,balance_after,created_at) VALUES ($u,'recharge','锁定回归开账',1,100000,100000,NOW())"); }
T('0.1 用户 A/B/C 就绪', $uidA > 0 && $uidB > 0 && $uidC > 0 && $tokA !== '' && $tokB !== '' && $tokC !== '', "A=$uidA B=$uidB C=$uidC");

$uc = seedHolder($uidA, 9611);
$r = http('POST', '/api/resale/listings', ['userCollectibleId' => $uc, 'price' => 88, 'paymentPassword' => 'Trade#2026'], $tokA);
$lid = ($r['code'] ?? -1) === 0 ? (int)($r['data']['id'] ?? $r['data']['listingId'] ?? 0) : 0;
T('0.2 A 挂单成功', $lid > 0, json_encode($r, JSON_UNESCAPED_UNICODE));
[$row, $lc] = poolRow($lid);
T('0.3 初始：池内可见且未锁定', $row !== null && empty($row['locked']) && $lc === 0, "locked=" . var_export($row['locked'] ?? null, true) . " lockedCount=$lc");

echo "\n========== 1. 下单未付款 → 锁定中 ==========\n";
$r = http('POST', '/api/orders', ['resaleListingId' => $lid, 'paymentPassword' => 'Trade#2026'], $tokB);
$orderNo = (string)($r['data']['orderNo'] ?? '');
T('1.1 B 下单（pending 不支付）', $orderNo !== '', "orderNo=$orderNo");
[$row, $lc, $floor] = poolRow($lid);
T('1.2 池内仍可见该挂单', $row !== null);
T('1.3 locked=true 且 lockedCount=1', ($row['locked'] ?? false) === true && $lc === 1, "locked=" . var_export($row['locked'] ?? null, true) . " lockedCount=$lc");
T('1.4 成交动态不含锁定单', !inHistory($lid));
T('1.5 DB 挂单状态 sold（锁定语义不变）', v("SELECT status FROM nft_resale_listings WHERE id=$lid") === 'sold');
$r2 = http('POST', '/api/orders', ['resaleListingId' => $lid, 'paymentPassword' => 'Trade#2026'], $tokB);
T('1.6 B 有未付款订单时再次下单被拒 3005', ($r2['code'] ?? 0) === 3005, "code={$r2['code']} msg={$r2['message']}");
$r3 = http('POST', '/api/orders', ['resaleListingId' => $lid, 'paymentPassword' => 'Trade#2026'], $tokC);
T('1.7 他人买锁定中的挂单仍被拒 1002', ($r3['code'] ?? 0) === 1002, "code={$r3['code']} msg={$r3['message']}");

echo "\n========== 2. 取消订单 → 恢复可售 ==========\n";
$r = http('POST', "/api/orders/$orderNo/cancel", null, $tokB);
T('2.1 B 取消订单成功', ($r['code'] ?? -1) === 0, "code={$r['code']} msg={$r['message']}");
[$row, $lc] = poolRow($lid);
T('2.2 池内 locked=false 且 lockedCount=0', $row !== null && empty($row['locked']) && $lc === 0, "locked=" . var_export($row['locked'] ?? null, true) . " lockedCount=$lc");
T('2.3 DB 挂单恢复 selling', v("SELECT status FROM nft_resale_listings WHERE id=$lid") === 'selling');

echo "\n========== 3. 下单并支付 → 移出池、进成交动态 ==========\n";
$r = http('POST', '/api/orders', ['resaleListingId' => $lid, 'paymentPassword' => 'Trade#2026'], $tokB);
$orderNo2 = (string)($r['data']['orderNo'] ?? '');
T('3.1 B 取消后可再次下单（未付款限制已解除）', $orderNo2 !== '', "msg=" . ($r['message'] ?? ''));
$r = http('POST', "/api/orders/$orderNo2/pay", ['paymentMethod' => 'balance', 'paymentPassword' => 'Trade#2026'], $tokB);
T('3.2 B 支付成功', ($r['code'] ?? -1) === 0, "code={$r['code']} msg={$r['message']}");
[$row, $lc] = poolRow($lid);
T('3.3 支付后挂单移出池', $row === null && $lc === 0);
T('3.4 成交动态出现该挂单', inHistory($lid));
T('3.5 资产过户给 B', (int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$uc") === $uidB);

echo "\n========== 4. 清理 ==========\n";
if (getenv('QA_KEEP')) {
  // 留下一条真实的「锁定中」夹具供前端目测：A 再挂一单，B 下单不付款
  $uc2 = seedHolder($uidA, 9611);
  $r = http('POST', '/api/resale/listings', ['userCollectibleId' => $uc2, 'price' => 66, 'paymentPassword' => 'Trade#2026'], $tokA);
  $lid2 = (int)($r['data']['listingId'] ?? 0);
  http('POST', '/api/orders', ['resaleListingId' => $lid2, 'paymentPassword' => 'Trade#2026'], $tokB);
  echo "QA_KEEP=1 已留下锁定中夹具：collectible=9611 listing={$lid2}（供前端目测），结果: PASS=$pass FAIL=$fail\n";
  exit($fail > 0 ? 1 : 0);
}
exe("SET FOREIGN_KEY_CHECKS=0");
foreach (['nft_wallets', 'nft_wallet_transactions', 'nft_user_collectibles', 'nft_orders', 'nft_payments', 'nft_buy_requests'] as $tb) { exe("DELETE FROM $tb WHERE user_id IN ($uidA,$uidB,$uidC)"); }
exe("DELETE FROM nft_transfers WHERE from_user_id IN ($uidA,$uidB,$uidC) OR to_user_id IN ($uidA,$uidB,$uidC)");
exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($uidA,$uidB,$uidC)");
exe("DELETE FROM nft_users WHERE id IN ($uidA,$uidB,$uidC)");
exe("DELETE FROM nft_collectibles WHERE id=9611");
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '139000092%'");
exe("SET FOREIGN_KEY_CHECKS=1");
echo "已清理测试数据\n";

echo "\n========== 结果: PASS=$pass FAIL=$fail ==========\n";
exit($fail > 0 ? 1 : 0);

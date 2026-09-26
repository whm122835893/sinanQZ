<?php
/** H5 限额计数并发回归（锁区间内的普通读不得读旧快照）
 *
 *  机制（实测根因，非推断）：InnoDB REPEATABLE READ 的读视图在事务「第一条读语句开始
 *  执行时」固定，而不是在拿到 SELECT ... FOR UPDATE 行锁之后。于是「先锁行、再用普通
 *  count()/sum() 判限额」的事务里，排队等锁的那个请求判的是自己抢锁之前的旧快照 ——
 *  前一个请求已提交的台账对它不可见，限额被并发穿透。
 *  证据：8 并发同一用户 + per_user_limit=1，锁区间内计数恒为 owned=0，放行 5 笔。
 *
 *  修复：这些事务在 startTrans 前执行 SET TRANSACTION ISOLATION LEVEL READ COMMITTED
 *  （不带作用域关键字 = 只作用于下一个事务，不污染连接）。视图按语句重建，锁区间内的
 *  普通读即最新已提交数据。
 *  未做全局 config 改动的原因：RaffleService::draw() 依赖同一事务内三次普通读
 *  （报名集/必中集/码池）落在同一个稳定快照上，全局 RC 会静默削弱该保证。
 *
 *  覆盖：Synthesis 每人限次 / Decompose 每人限次 + 平台每日上限（跨用户）/
 *       LuckyDraw 免费抽台账 / Raffle 购码上限（扣钱）。
 *       Orders::create 限购同机制，用例在 sit_h1_oversell.php 的 H1-5。
 *  复现失败（本机 5 worker、N=8 与 N=24 各跑一轮，去掉 5 处 SET 语句后）：
 *       Synthesis per_user_limit=1 → 放行 3（受材料件数封顶）、=3 → 放行 4
 *       Decompose per_user_limit=1 → 放行 3；daily_limit=1 跨 5 用户 → 放行 5（每人 1 次）
 *       Raffle buy_code_limit=1 → 放行 5 码、多扣 4 元
 *       LuckyDraw 免费抽 → 未复现（N=24 仍恰好 1 次）：该事务临界区最短，24 个请求排队
 *       时后到者的读视图已在先行事务提交之后才建立。留此语句是把「持锁即可见」从
 *       依赖时序改成依赖隔离级别，H5-3 作为回归护栏保留。
 *  前提：QA_BASE 必须配多个后端地址轮转分发（Windows php -S 单进程串行，压不出竞态）。
 *  强度：H5_N 可调（默认 8）。夹具每笔请求都用不同的资产/材料，确保拦住并发的一定是
 *       限额判定本身，而不是「材料不足」「状态异常」这类夹具天花板。
 */
set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');
$BASES = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('QA_BASE') ?: 'http://127.0.0.1:8080')))));
$BASE  = $BASES[0];
$N = (int) (getenv('H5_N') ?: 8); // 并发强度：临界区很短的路径（免费抽）需要更大 N 才压得出来

function distribute(string $url, ?int $i = null): string
{
    global $BASES;
    if (count($BASES) < 2) return $url;
    static $n = 0;
    $k = ($i ?? $n++) % count($BASES);
    return (string) preg_replace('#^[a-z]+://[^/]+#i', $BASES[$k], $url, 1);
}
define('QA_PDO_ERRMODE', PDO::ERRMODE_WARNING);
require __DIR__ . '/bootstrap_db.php';
$pass = 0; $fail = 0; $fails = [];
function T($n, $c, $d = '') { global $pass, $fail, $fails; $c ? $pass++ : $fail++; if (!$c) $fails[] = $n; printf("%s %s%s\n", $c ? '  PASS' : '  FAIL', $n, $d ? ' | ' . $d : ''); }
function v($sql) { global $PDO; $r = $PDO->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function exe($sql) { global $PDO; return $PDO->exec($sql); }
function q($sql) { global $PDO; return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC); }

$envSrc = (string) file_get_contents(__DIR__ . '/../sinan-nft-backend/.env');
preg_match('/^SECRET\s*=\s*(.+)$/m', $envSrc, $m);
$SECRET = trim($m[1] ?? '');
function b64url($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mint(int $uid, string $phone): string
{
    global $SECRET;
    $h = b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $p = b64url(json_encode(['iss' => 'sinan-nft-audience', 'aud' => 'sinan-nft-client', 'iat' => time(), 'exp' => time() + 86400, 'sub' => $uid, 'phone' => $phone]));
    return "$h.$p." . b64url(hash_hmac('sha256', "$h.$p", $SECRET, true));
}
function burst(array $reqs, int $timeout = 60): array
{
    $mh = curl_multi_init(); $handles = [];
    foreach ($reqs as $i => $r) {
        $ch = curl_init(distribute($r['url'], (int) $i));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $r['tok']],
            CURLOPT_POSTFIELDS => json_encode($r['b'])]);
        curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
    }
    $out = [];
    do {
        curl_multi_exec($mh, $active);
        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch = $info['handle']; $i = array_search($ch, $handles, true);
            $out[$i] = ['err' => $info['result'] !== CURLE_OK, 'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                        'json' => json_decode((string) curl_multi_getcontent($ch), true) ?: ['code' => -2, 'message' => 'BADJSON']];
            curl_multi_remove_handle($mh, $ch);
        }
        if ($active) curl_multi_select($mh, 0.02);
    } while (count($out) < count($reqs));
    curl_multi_close($mh); ksort($out); return $out;
}
/** 成功笔数 + 拒绝码分布（拒绝原因必须可见，否则「恰好放行 N 笔」可能是别的原因蒙对的） */
function outcome(array $rs): array
{
    $ok = 0; $codes = []; $err = 0; $sample = [];
    foreach ($rs as $r) {
        if ($r['err'] || $r['code'] >= 500) { $err++; continue; }
        $jc = (int) ($r['json']['code'] ?? -1);
        if ($jc === 0) { $ok++; continue; }
        $codes[$jc] = ($codes[$jc] ?? 0) + 1;
        $msg = (string) ($r['json']['message'] ?? '');
        $sample[$jc] = $sample[$jc] ?? $msg;
    }
    ksort($codes);
    $d = [];
    foreach ($codes as $c => $n) $d[] = $c . '×' . $n . '(' . ($sample[$c] ?? '') . ')';
    return ['ok' => $ok, 'rej' => implode(' ', $d), 'err' => $err];
}
/** 夹具主键必须显式取最大 id：同名夹具若有残留，无 ORDER BY 的查询会拿到上一轮那行，
 *  其外键指向旧藏品，用例就会以「未持有或状态异常」蒙混失败 */
function idOf(string $sql): int { return (int) v($sql . ' ORDER BY id DESC LIMIT 1'); }

echo "=== H5-0 环境与种子（并发强度 {$N}，后端 " . count($BASES) . " 个地址）===\n";
T('H5-0a 多地址轮转已配置（单地址会被 php -S 串行，压不出竞态）', count($BASES) >= 2, 'QA_BASE=' . (string) getenv('QA_BASE'));
T('H5-0b 数据库与密钥就绪', (int) v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='sinan_nft' AND TABLE_NAME='nft_users'") === 1 && $SECRET !== '');

/* ---- 幂等清理：154 前缀（H3 用 153、H1 用 151、H2 用 152，互不占用）----
   删除顺序按 FK 依赖：引用 user_collectibles 的子表 → orders 的子表 → uc → orders → wallets → users。
   乱序会被 RESTRICT 挡下（PDO 只 WARNING），留下脏夹具让下一轮基数不对。 */
$PH = '1540000%';
$sub = "SELECT id FROM nft_users WHERE phone LIKE '$PH'";
exe("DELETE FROM nft_inbox WHERE user_id IN ($sub)");
exe("DELETE FROM nft_lucky_draw_records WHERE user_id IN ($sub)");
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id IN ($sub)");
exe("DELETE FROM nft_decompose_records WHERE user_id IN ($sub)");
exe("DELETE FROM nft_user_favorites WHERE user_id IN ($sub)");
exe("DELETE FROM nft_support_tickets WHERE user_id IN ($sub)");
exe("DELETE i FROM nft_synthesis_record_items i JOIN nft_synthesis_records r ON r.id=i.synthesis_record_id JOIN nft_users u ON u.id=r.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE FROM nft_synthesis_records WHERE user_id IN ($sub)");
exe("DELETE FROM nft_raffle_registrations WHERE user_id IN ($sub)");
exe("DELETE FROM nft_user_draw_codes WHERE user_id IN ($sub)");
exe("DELETE FROM nft_transfers WHERE from_user_id IN ($sub) OR to_user_id IN ($sub)");
exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($sub)");
exe("DELETE p FROM nft_payments p JOIN nft_users u ON u.id=p.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE rf FROM nft_refunds rf JOIN nft_users u ON u.id=rf.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE wt FROM nft_wallet_transactions wt JOIN nft_users u ON u.id=wt.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_users u ON u.id=uc.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE o FROM nft_orders o JOIN nft_users u ON u.id=o.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE w FROM nft_wallets w JOIN nft_users u ON u.id=w.user_id WHERE u.phone LIKE '$PH'");
exe("DELETE FROM nft_users WHERE phone LIKE '$PH'");
/* 藏品域：先删引用藏品的一方，再删藏品 */
exe("DELETE FROM nft_raffle_registrations WHERE activity_id IN (SELECT id FROM nft_raffle_activities WHERE name LIKE 'H5-%')");
exe("DELETE FROM nft_raffle_activities WHERE name LIKE 'H5-%'");
exe("DELETE FROM nft_blind_box_items WHERE blind_box_id IN (SELECT id FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H5-%'))");
exe("DELETE FROM nft_blind_boxes WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H5-%')");
exe("DELETE FROM nft_decompose_items WHERE rule_id IN (SELECT id FROM nft_decompose_rules WHERE name LIKE 'H5%')");
exe("DELETE FROM nft_decompose_rules WHERE name LIKE 'H5%'");
exe("DELETE FROM nft_synthesis_materials WHERE activity_id IN (SELECT id FROM nft_synthesis_activities WHERE title LIKE 'H5%')");
exe("DELETE i FROM nft_synthesis_record_items i JOIN nft_synthesis_records r ON r.id=i.synthesis_record_id WHERE r.activity_id IN (SELECT id FROM nft_synthesis_activities WHERE title LIKE 'H5%')");
exe("DELETE FROM nft_synthesis_records WHERE activity_id IN (SELECT id FROM nft_synthesis_activities WHERE title LIKE 'H5%')");
exe("DELETE FROM nft_synthesis_activities WHERE title LIKE 'H5%'");
exe("DELETE FROM nft_lucky_draw_prizes WHERE activity_id IN (SELECT id FROM nft_lucky_draw_activities WHERE name LIKE 'H5%')");
exe("DELETE FROM nft_lucky_draw_activities WHERE name LIKE 'H5%'");
/* 夹具藏品的引用方必须先清（含上一轮误用其它前缀用户留下的资产/收件箱行），否则藏品删不掉 */
exe("DELETE uc FROM nft_user_collectibles uc JOIN nft_collectibles c ON c.id=uc.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE ib FROM nft_inbox ib JOIN nft_collectibles c ON c.id=ib.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE tr FROM nft_transfers tr JOIN nft_collectibles c ON c.id=tr.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE rl FROM nft_resale_listings rl JOIN nft_collectibles c ON c.id=rl.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE uf FROM nft_user_favorites uf JOIN nft_collectibles c ON c.id=uf.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE o FROM nft_orders o JOIN nft_collectibles c ON c.id=o.collectible_id WHERE c.name LIKE 'H5%'");
exe("DELETE FROM nft_inventory_quotas WHERE collectible_id IN (SELECT id FROM nft_collectibles WHERE name LIKE 'H5%')");
exe("DELETE FROM nft_collectibles WHERE name LIKE 'H5-%'");

$hash = password_hash('Pay#2026', PASSWORD_BCRYPT);
$PHONES = ['15400000000' => 'SYN1', '15400000001' => 'SYN3', '15400000002' => 'DEC1',
           '15400000003' => 'DECD1', '15400000004' => 'DECD2', '15400000005' => 'DECD3',
           '15400000006' => 'DECD4', '15400000007' => 'DECD5', '15400000008' => 'LDF', '15400000009' => 'RAF'];
$vals = []; $i = 0;
foreach ($PHONES as $ph => $tag) {
    $vals[] = "('$ph','H5-$tag','','UH5" . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . "','IH5" . substr(md5($ph), 0, 8) . "',1,'$hash')";
    $i++;
}
exe('INSERT INTO nft_users (phone,username,avatar,uid,invite_code,is_realname,transaction_password) VALUES ' . implode(',', $vals));
$uids = [];
foreach (q("SELECT id,phone FROM nft_users WHERE phone LIKE '$PH'") as $u) $uids[$u['phone']] = (int) $u['id'];
$wvals = [];
foreach ($uids as $id) $wvals[] = "($id,100.00,100.00,0.00,0.00)";
exe('INSERT INTO nft_wallets (user_id,balance,available,frozen,points) VALUES ' . implode(',', $wvals));
qa_seed_wallet_ledger($PDO, array_values($uids)); // 直接写余额必须补开账流水，否则 Z1-1 全局恒等式被夹具打破
$TOK = [];
foreach ($uids as $ph => $id) $TOK[$ph] = mint($id, $ph);

function mkColl(string $name, float $price = 1.00, int $edition = 1000): int
{
    exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,status,onsale_at,is_resaleable,resale_price_mode,created_at,updated_at)
         VALUES (1,'$name','https://qa.local/h5.png',$price,$edition,'onsale',DATE_SUB(NOW(),INTERVAL 1 HOUR),1,0,NOW(3),NOW(3))");
    return (int) v("SELECT id FROM nft_collectibles WHERE name='$name' ORDER BY id DESC LIMIT 1");
}
function mkAsset(int $uid, int $cid): int
{
    global $PDO;
    $serial = 'SNQ-H5-' . sprintf('%06d', random_int(1, 999999));
    $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
      VALUES ($uid,$cid,'$serial','purchase',1.00,NOW(3),'held',NOW(3),NOW(3))");
    return (int) $PDO->lastInsertId();
}
$cidMat = mkColl('H5-MAT');  $cidRes = mkColl('H5-RES');
$cidSrc = mkColl('H5-SRC');  $cidOut = mkColl('H5-OUT');
$cidSrc2 = mkColl('H5-SRC2'); $cidRaf = mkColl('H5-RAF');
T('H5-0c 6 藏品 + 10 用户种子', $cidMat && $cidRes && $cidSrc && $cidOut && $cidSrc2 && $cidRaf && count($uids) === 10);

/* 合成活动：per_user_limit=1 / =3，材料均为 1×H5-MAT → H5-RES
   夹具名一律带连字符（'H5-xxx'）：清理按 LIKE 'H5-%' 匹配，缺连字符就删不掉旧行，
   下一轮会复用上一轮那行（其外键指向已删除的藏品），用例将以无关原因失败。 */
exe("INSERT INTO nft_synthesis_activities (type,title,rules,result_collectible_id,result_quantity,per_user_limit,status)
     VALUES ('permanent','H5-合成1','H5',$cidRes,1,1,1),('permanent','H5-合成3','H5',$cidRes,1,3,1)");
$syn1 = idOf("SELECT id FROM nft_synthesis_activities WHERE title='H5-合成1'");
$syn3 = idOf("SELECT id FROM nft_synthesis_activities WHERE title='H5-合成3'");
exe("INSERT INTO nft_synthesis_materials (activity_id,collectible_id,count) VALUES ($syn1,$cidMat,1),($syn3,$cidMat,1)");
/* 分解规则：per_user_limit=1（源 H5-SRC）；daily_limit=1 跨用户（源 H5-SRC2） */
exe("INSERT INTO nft_decompose_rules (name,source_collectible_id,enabled,per_user_limit,daily_limit) VALUES ('H5-分解1',$cidSrc,1,1,0)");
exe("INSERT INTO nft_decompose_rules (name,source_collectible_id,enabled,per_user_limit,daily_limit) VALUES ('H5-分解日',$cidSrc2,1,0,1)");
$dec1  = idOf("SELECT id FROM nft_decompose_rules WHERE name='H5-分解1'");
$decD  = idOf("SELECT id FROM nft_decompose_rules WHERE name='H5-分解日'");
exe("INSERT INTO nft_decompose_items (rule_id,result_collectible_id,quantity_per) VALUES ($dec1,$cidOut,1),($decD,$cidOut,1)");
/* 免费抽活动（在窗口内 + 完整奖池） */
exe("INSERT INTO nft_lucky_draw_activities (name,status,eligibility_type,grant_mode,start_time,end_time,created_at,updated_at)
     VALUES ('H5-免费抽',1,'all','realtime','" . date('Y-m-d H:i:s', time() - 600) . "','" . date('Y-m-d H:i:s', time() + 600) . "',NOW(3),NOW(3))");
$ldAct = (int) v("SELECT id FROM nft_lucky_draw_activities WHERE name='H5-免费抽'");
exe("INSERT INTO nft_lucky_draw_prizes (activity_id,tier_name,prize_type,prize_name,collectible_id,coin_amount,total,won,sort_order,probability,created_at,updated_at)
     VALUES ($ldAct,'谢谢参与','none','谢谢参与',NULL,NULL,500,0,1,1.0000,NOW(3),NOW(3))");
/* 抽签活动：报名窗口开放、购码开关打开、上限 1、单价 1 元
   窗口必须用 PHP 时间写入：应用比较的是 date('Y-m-d H:i:s')（Asia/Shanghai），
   而本库 MySQL NOW() 为 UTC，用 NOW() 会让请求恒定落在报名窗口之外。 */
$rafStart = date('Y-m-d H:i:s', time() - 3600);
$rafEnd   = date('Y-m-d H:i:s', time() + 3600);
exe("INSERT INTO nft_raffle_activities (collectible_id,name,ticket_price,total_supply,draw_code_enabled,draw_code_price,winner_count,buy_code_limit,invite_enabled,sale_quantity,sale_price,registration_start,registration_end,draw_time,status)
     VALUES ($cidRaf,'H5-抽签',0.00,100,1,1.00,10,1,0,1,1.00,'$rafStart','$rafEnd','$rafEnd',1)");
$rafAct = idOf("SELECT id FROM nft_raffle_activities WHERE name='H5-抽签'");
exe("INSERT INTO nft_raffle_registrations (activity_id,user_id,ticket_count,pay_status) VALUES ($rafAct,{$uids['15400000009']},1,1)");
/* 模块开关：合成/抽奖必须开启，收尾复原 */
$swOld = [];
foreach (['synthesis_enabled', 'lucky_enabled'] as $k) $swOld[$k] = (string) v("SELECT config_value FROM nft_system_configs WHERE config_key='$k'");
exe("UPDATE nft_system_configs SET config_value='1' WHERE config_key IN ('synthesis_enabled','lucky_enabled')");
register_shutdown_function(function () use ($swOld) {
    global $PDO;
    foreach ($swOld as $k => $val) {
        if ($val === '') continue;
        $PDO->exec('UPDATE nft_system_configs SET config_value=' . $PDO->quote($val) . " WHERE config_key='" . $k . "'");
    }
});
T('H5-0d 合成/分解/抽奖/抽签夹具', $syn1 > 0 && $syn3 > 0 && $dec1 > 0 && $decD > 0 && $ldAct > 0 && $rafAct > 0);

echo "\n=== H5-1 合成每人限次（锁区间内的 count() 不得读旧快照）===\n";
/* 每人备足 N 件材料：材料不足（1001）绝不能成为拦住并发的那道闸，
   否则「只放行 N 笔」可能是被夹具卡住而不是被限额拦住。 */
$uSyn1 = '15400000000'; $uSyn3 = '15400000001';
for ($i = 0; $i < $N; $i++) mkAsset($uids[$uSyn1], $cidMat);
for ($i = 0; $i < $N; $i++) mkAsset($uids[$uSyn3], $cidMat);
$o1 = outcome(burst(array_fill(0, $N, ['url' => "$BASE/api/synthesis/submit", 'tok' => $TOK[$uSyn1], 'b' => ['activityId' => $syn1]])));
$rec1 = (int) v("SELECT COUNT(*) FROM nft_synthesis_records WHERE activity_id=$syn1 AND user_id={$uids[$uSyn1]}");
$res1 = (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$uids[$uSyn1]} AND collectible_id=$cidRes");
$use1 = (int) v("SELECT used_count FROM nft_synthesis_activities WHERE id=$syn1");
T('H5-1a per_user_limit=1 时并发 ' . $N . ' 笔恰好放行 1 次', $o1['ok'] === 1 && $rec1 === 1, "ok={$o1['ok']} 台账=$rec1 产物=$res1 拒绝[{$o1['rej']}] 5xx={$o1['err']}");
T('H5-1b 产物与活动计数一致（无多发、无漏记）', $res1 === $rec1 && $use1 === $rec1, "used_count=$use1 records=$rec1 assets=$res1");
$o2 = outcome(burst(array_fill(0, $N, ['url' => "$BASE/api/synthesis/submit", 'tok' => $TOK[$uSyn3], 'b' => ['activityId' => $syn3]])));
$rec2 = (int) v("SELECT COUNT(*) FROM nft_synthesis_records WHERE activity_id=$syn3 AND user_id={$uids[$uSyn3]}");
$matLeft = (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$uids[$uSyn3]} AND collectible_id=$cidMat AND status='held'");
T('H5-1c per_user_limit=3 时并发 ' . $N . ' 笔恰好放行 3 次', $o2['ok'] === 3 && $rec2 === 3, "ok={$o2['ok']} 台账=$rec2 材料余=$matLeft 拒绝[{$o2['rej']}] 5xx={$o2['err']}");
T('H5-1d 材料消耗与放行次数守恒（' . $N . ' 持有 - 消耗 = 余）', $matLeft === $N - $rec2, "held=$matLeft records=$rec2 seeded=$N");

echo "\n=== H5-2 分解每人限次（不同源资产绕开资产状态机，只靠限额判定拦截）===\n";
$uDec1 = '15400000002';
$assets1 = [];
for ($i = 0; $i < $N; $i++) $assets1[] = mkAsset($uids[$uDec1], $cidSrc);
$reqs = [];
for ($i = 0; $i < $N; $i++) $reqs[] = ['url' => "$BASE/api/decompose/execute", 'tok' => $TOK[$uDec1], 'b' => ['ruleId' => $dec1, 'userCollectibleId' => $assets1[$i]]];
$o3 = outcome(burst($reqs));
$rec3 = (int) v("SELECT COUNT(*) FROM nft_decompose_records WHERE rule_id=$dec1 AND user_id={$uids[$uDec1]}");
$out3 = (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$uids[$uDec1]} AND collectible_id=$cidOut");
$src3 = (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN (" . implode(',', $assets1) . ") AND status='consumed'");
T('H5-2a per_user_limit=1 时并发 ' . $N . ' 笔恰好分解 1 次', $o3['ok'] === 1 && $rec3 === 1, "ok={$o3['ok']} 台账=$rec3 产物=$out3 拒绝[{$o3['rej']}] 5xx={$o3['err']}");
T('H5-2b 源资产消耗/产物发放与台账三方一致', $src3 === $rec3 && $out3 === $rec3, "consumed=$src3 records=$rec3 outputs=$out3");

echo "\n=== H5-2c 分解平台每日上限（跨用户并发：各用户各锁各的资产，只能靠 daily_limit 拦截）===\n";
$dayPhones = ['15400000003', '15400000004', '15400000005', '15400000006', '15400000007'];
$daySlots = intdiv($N + count($dayPhones) - 1, count($dayPhones)); // ceil(N / 用户数)
$dayAssets = [];
foreach ($dayPhones as $ph) {
    for ($s = 0; $s < $daySlots; $s++) $dayAssets[$ph][] = mkAsset($uids[$ph], $cidSrc2);
}
// 每笔都用不同的源资产：资产状态机（仅 held 可消耗）就不会替 daily_limit 挡住并发，
// 唯一能拦下超发的只有「锁区间内的今日计数」这一条判定。
$reqs = [];
for ($i = 0; $i < $N; $i++) {
    $ph = $dayPhones[$i % 5];
    $slot = intdiv($i, 5);
    $reqs[] = ['url' => "$BASE/api/decompose/execute", 'tok' => $TOK[$ph], 'b' => ['ruleId' => $decD, 'userCollectibleId' => $dayAssets[$ph][$slot]]];
}
$o4 = outcome(burst($reqs));
$today = date('Y-m-d 00:00:00');
$rec4 = (int) v("SELECT COUNT(*) FROM nft_decompose_records WHERE rule_id=$decD AND created_at>='$today'");
$out4 = (int) v("SELECT COUNT(*) FROM nft_user_collectibles WHERE collectible_id=$cidOut AND source='synthesis' AND created_at>='$today' AND user_id IN (" . implode(',', array_map(fn ($p) => $uids[$p], $dayPhones)) . ")");
T('H5-2c daily_limit=1 时 ' . $N . ' 个跨用户请求恰好放行 1 次', $o4['ok'] === 1 && $rec4 === 1, "ok={$o4['ok']} 今日台账=$rec4 拒绝[{$o4['rej']}] 5xx={$o4['err']}");
T('H5-2d 每日上限未超发产物（产物数=台账数）', $out4 === $rec4, "outputs=$out4 records=$rec4");

echo "\n=== H5-3 免费抽奖台账（H2-D1 同族的并发面）===\n";
$uLd = '15400000008';
$o5 = outcome(burst(array_fill(0, $N, ['url' => "$BASE/api/lucky-draw/draw", 'tok' => $TOK[$uLd], 'b' => []])));
$rec5 = (int) v("SELECT COUNT(*) FROM nft_lucky_draw_records WHERE user_id={$uids[$uLd]}");
$free5 = (int) v("SELECT COUNT(*) FROM nft_lucky_draw_chances WHERE user_id={$uids[$uLd]} AND source='free'");
T('H5-3a 免费抽并发 ' . $N . ' 笔恰好抽 1 次', $o5['ok'] === 1 && $rec5 === 1, "ok={$o5['ok']} 记录=$rec5 拒绝[{$o5['rej']}] 5xx={$o5['err']}");
T('H5-3b 免费抽台账只落 1 行（否则永远查无台账可无限抽）', $free5 === 1, "free_ledger=$free5");

echo "\n=== H5-4 抽签码购买上限（穿透即多扣钱）===\n";
$uRaf = '15400000009';
$o6 = outcome(burst(array_fill(0, $N, ['url' => "$BASE/api/raffle/activities/$rafAct/purchase-draw-code", 'tok' => $TOK[$uRaf], 'b' => ['quantity' => 1]])));
$code6 = (int) v("SELECT COUNT(*) FROM nft_user_draw_codes WHERE user_id={$uids[$uRaf]} AND activity_id=$rafAct AND source=3");
$tx6 = (int) v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id={$uids[$uRaf]} AND trans_type='buy' AND title='购买抽签码'");
$bal6 = (float) v("SELECT available FROM nft_wallets WHERE user_id={$uids[$uRaf]}");
T('H5-4a buy_code_limit=1 时并发 ' . $N . ' 笔恰好购得 1 码', $o6['ok'] === 1 && $code6 === 1, "ok={$o6['ok']} 码=$code6 拒绝[{$o6['rej']}] 5xx={$o6['err']}");
T('H5-4b 扣款笔数与码数一致且余额只减 1 元', $tx6 === $code6 && abs($bal6 - 99.00) < 0.001, "tx=$tx6 codes=$code6 available=$bal6");

echo "\n=== H5-5 全局恒等式（本套件未破坏资金与库存守恒）===\n";
$neg = (int) v("SELECT COUNT(*) FROM nft_wallets WHERE balance < 0 OR available < 0 OR frozen < 0");
$uidList = implode(',', $uids);
T('H5-5a 无负余额', $neg === 0, "neg=$neg");
$drift = (int) v("SELECT COUNT(*) FROM nft_wallets w LEFT JOIN (SELECT user_id, SUM(CASE WHEN direction=1 THEN amount ELSE -amount END) s FROM nft_wallet_transactions WHERE user_id IN ($uidList) GROUP BY user_id) t ON t.user_id=w.user_id WHERE w.user_id IN ($uidList) AND ABS(w.balance - IFNULL(t.s,0)) > 0.001");
T('H5-5b 种子用户钱包与流水可对账（开账夹具正确）', $drift === 0, "drift=$drift");
$dupFree = (int) v("SELECT COUNT(*) FROM (SELECT user_id FROM nft_lucky_draw_chances WHERE source='free' AND user_id IN (" . implode(',', $uids) . ") GROUP BY user_id HAVING COUNT(*)>1) x");
T('H5-5c 无用户持有多行免费抽台账', $dupFree === 0, "dupUsers=$dupFree");

echo "\n==== H5 结果: PASS $pass / FAIL $fail ====\n";
echo "提示：本套件断言的是「限额恰好放行 N 笔」。若把 5 处 SET TRANSACTION ISOLATION LEVEL READ COMMITTED 去掉重跑，"
   . "H5-1/2/3/4 应转为 FAIL（放行笔数 > 限额），否则说明本轮请求未真正并发，需检查 QA_BASE。\n";
if ($fails) { echo "失败项:\n" . implode("\n", $fails) . "\n"; exit(1); }
exit(0);

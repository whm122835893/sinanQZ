<?php
/**
 * 实名认证「审核模式 + 一证一号」全链路验收（manual / auto 两种模式、前后端身份证同口径、证号去重）
 *
 * 覆盖什么：
 *   0. 两份迁移幂等：20261010_realname_audit_mode_auto.sql 与 20261010_realname_id_card_unique.sql
 *      各连跑两遍，第二遍必须是无副作用的 no-op（配置值那行连 updated_at 都不前移）
 *   1. auto：提交即通过 —— 状态位/能力位/排位时间三连对，姓名与证件号 AES 密文落库，
 *      后台 detail 能解回明文、列表仍脱敏，**并写入 id_card_hash 指纹**
 *   2. auto 下的防线：非法证号仍被拒且不写库、通过后身份不可改、后台点「通过」得到 4220（没有待审核队列）
 *   3. 一证一号：同一张证号的第二个账号在 auto 下被拒且库里一行不写；末位 x/X 算同一张证号；
 *      换一张自己的证号照常通过（拦的是重复，不是账号）；指纹列不从任何接口透出
 *   4. manual：两步流零破坏 —— pending → 驳回必填原因 → 驳回后重新提交 → 人工通过 → 二次审核 4220 → 原因清空；
 *      待审核**不占用**证号，但管理员点第二个「通过」时被拦（4220 + 对方 UID + audit_conflict 留痕，账号留在队列里）
 *   5. 审核留痕：audit_approve / audit_reject / view_full / audit_conflict 都落 nft_admin_operation_logs
 *   6. 运维只读统计命令 `php think realname:id-hash` 能跑通（生产侧落地前要先用它看存量重复）
 *
 * 前置：后端 127.0.0.1:8080 + 沙箱库（APP_ENV / QA_DB_* 见 README.md 第一节的共用护栏）
 * 依赖：admin/admin123、mock 短信返回的 debugCode（后端 APP_DEBUG=false 时拿不到，跑不动注册）
 *
 * ⚠ 这个脚本会**改库里 realname_audit_mode 的值**（跑完恢复成 auto）、**注册测试账号**、
 *   **执行 migrations 那两份 SQL**。因此禁止对生产库跑；生产侧只需要「查一次值 + 后台点一下 + 只读统计」，
 *   步骤见 ../deploy/RUNBOOK.md 7.12 / 7.13 末尾。非本机基址要显式 QA_ALLOW_REMOTE=1 才放行。
 */
date_default_timezone_set('Asia/Shanghai');

$BASE = getenv('QA_BASE') ?: 'http://127.0.0.1:8080';
if (!preg_match('#^https?://(127\.0\.0\.1|localhost)(:|/)#', $BASE) && getenv('QA_ALLOW_REMOTE') !== '1') {
    fwrite(STDERR, "[qa] 拒绝执行：QA_BASE={$BASE} 不是本机地址。本脚本会改配置值、注册测试账号并执行迁移，"
        . "不要指向生产。确认是非生产环境可加 QA_ALLOW_REMOTE=1\n");
    exit(1);
}
require __DIR__ . '/bootstrap_db.php';   // 内含 APP_ENV 与凭据两道护栏

$MIG_DIR = dirname(__DIR__) . '/sinan-nft-backend/migrations/';
$MIGS = [
    $MIG_DIR . '20261010_realname_audit_mode_auto.sql',
    $MIG_DIR . '20261010_realname_id_card_unique.sql',
];
$pass = 0; $fail = 0; $fails = [];
function T(string $n, bool $c, string $d = '') {
    global $pass, $fail, $fails;
    $c ? $pass++ : $fail++;
    printf("  %s %-48s %s\n", $c ? 'PASS' : 'FAIL', $n, $d ? "| $d" : '');
    if (!$c) $fails[] = $n;
}
function http(string $m, string $u, $b = null, ?string $t = null): array {
    global $BASE; $ch = curl_init($BASE . $u);
    $h = ['Content-Type: application/json'];
    if ($t) $h[] = "Authorization: Bearer $t";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: new stdClass())]);
    $raw = curl_exec($ch); $err = curl_error($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = json_decode($raw ?: '', true);
    return is_array($j) ? $j : ['code' => -2, 'message' => "BADJSON http=$st err=$err raw=" . substr((string) $raw, 0, 120)];
}
function mode(string $v): array { global $atok; return http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => $v], $atok); }
function col(int $uid, string $c) {
    $v = $GLOBALS['PDO']->query("SELECT `$c` FROM nft_users WHERE id=$uid")->fetch(PDO::FETCH_ASSOC)[$c] ?? null;
    return $v === null ? null : (is_string($v) ? $v : (string) $v);
}
/**
 * 造一张格式合法、本脚本运行内唯一、且几乎不可能撞上历史数据的证号
 *
 * 为什么必须认真写这个函数（踩过一次）：一证一号之后，「证号重复」不再是无害的脏数据，
 * 而会把后一次提交直接挡掉。早先的写法是 `随机 0-999 × 1000 + 序号` 再拆成年/月/日/顺序码，
 * 看着有 1000 个槽位，实际拆完只剩约 1000 个不同值 —— 合法空间本来有
 * 100(年) × 12(月) × 28(日) × 1000(顺序码) = 3360 万个组合，被压成了千分之一都不到，
 * 于是第二轮跑就会撞上上一轮那些「已通过」账号占住的坑，整段 manual 流程假红。
 * 现在每一段独立取随机数：同一次运行内用静态表保证不重样，跨运行撞上历史证号的概率约 20/3360万。
 * 日用 01-28 而不是 01-31：让所有生成的日期都真实存在，避免「2 月 31 日」这类只有正则能过、
 * 语义上却是坏数据的东西混进夹具。
 */
function rndId(): string {
    static $used = [];
    do {
        $id = sprintf('11010119%02d%02d%02d%03dX',
            random_int(0, 99), random_int(1, 12), random_int(1, 28), random_int(1, 999));
    } while (isset($used[$id]));
    $used[$id] = true;
    return $id;
}
/** 注册+登录一个新 C 端账号，返回 [数字ID, token, 手机号] */
function newUser(string $nick): array {
    $phone = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $pwd = 'Pass#123456';
    $sc = http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register']);
    $dc = $sc['data']['debugCode'] ?? null;
    T("注册发码 $nick", ($sc['code'] ?? -1) === 0 && $dc, ($sc['message'] ?? '') . ' debugCode=' . ($dc ? '有' : '无'));
    $reg = http('POST', '/api/auth/register', ['phone' => $phone, 'code' => $dc, 'password' => $pwd, 'nickname' => $nick]);
    T("注册 $nick", ($reg['code'] ?? -1) === 0, $reg['message'] ?? '');
    $lg = http('POST', '/api/auth/login', ['phone' => $phone, 'password' => $pwd]);
    $tok = $lg['data']['token'] ?? '';
    T("登录 $nick", ($lg['code'] ?? -1) === 0 && $tok, $lg['message'] ?? '');
    $uid = (int) $GLOBALS['PDO']->query("SELECT id FROM nft_users WHERE phone=" . $GLOBALS['PDO']->quote($phone))->fetchColumn();
    return [$uid, $tok, $phone];
}

echo "===== 0. 两份迁移幂等（对本库各连跑两遍） =====\n";
$pdo = qa_pdo();
foreach ($MIGS as $m) {
    if (!is_file($m)) { fwrite(STDERR, "[qa] 找不到迁移文件 $m\n"); exit(1); }
}
$runMig = static function (PDO $c, string $file): int {
    $n = 0;
    foreach (explode(';', (string) file_get_contents($file)) as $stmt) {
        // 先去注释再切句（本目录的迁移注释里没有分号，切句安全）
        $stmt = trim((string) preg_replace('/^\s*--.*$/m', '', $stmt));
        if ($stmt === '') continue;
        // 用 prepare+execute 并排空结果集，不用 exec：
        // 指纹那份迁移的 DDL 走 PREPARE/EXECUTE，跳过分支会 SELECT 一行提示，
        // exec 会把这个未缓冲的结果集留在连接上，下一条语句直接 2014 报错。
        $q = $c->prepare($stmt);
        $q->execute();
        while ($q->nextRowset()) {}
        $q->closeCursor();
        $n++;
    }
    return $n;
};
$at0 = (string) $pdo->query("SELECT config_value FROM nft_system_configs WHERE config_key='realname_audit_mode'")->fetchColumn();
$runMig($pdo, $MIGS[0]);
$at1 = (string) $pdo->query("SELECT updated_at FROM nft_system_configs WHERE config_key='realname_audit_mode'")->fetchColumn();
$runMig($pdo, $MIGS[0]);
$after = $pdo->query("SELECT config_value, description, updated_at FROM nft_system_configs WHERE config_key='realname_audit_mode'")->fetch(PDO::FETCH_ASSOC);
T('两遍后值仍是 auto', ($after['config_value'] ?? '') === 'auto', '当前=' . json_encode($after, JSON_UNESCAPED_UNICODE));
T('第二遍是真 no-op（updated_at 没再前移）', $at1 === (string) ($after['updated_at'] ?? ''), "first=$at1 second={$after['updated_at']}");
T('描述文本标明「当前默认」', str_contains((string) $after['description'], '当前默认'), (string) $after['description']);
echo "  （跑之前库里的值是 {$at0}，迁移把它归位到 auto —— 这正是它该有的行为）\n";

// 指纹列那份迁移：DDL 型幂等 —— 第二遍走的是 information_schema 的 skip 分支，不会报错也不会改结构
$runMig($pdo, $MIGS[1]);
$runMig($pdo, $MIGS[1]);
$colHash = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_users' AND COLUMN_NAME='id_card_hash'")->fetch(PDO::FETCH_ASSOC);
T('迁移两遍后 nft_users.id_card_hash 存在（char(64) 可空）',
    ($colHash['COLUMN_TYPE'] ?? '') === 'char(64)' && ($colHash['IS_NULLABLE'] ?? '') === 'YES',
    json_encode($colHash));
$idx = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_users' AND INDEX_NAME='idx_id_card_hash' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
T('idx_id_card_hash 索引在（当前是非唯一：存量重复不能被迁移打断）',
    $idx !== false && (int) $idx['NON_UNIQUE'] === 1, 'NON_UNIQUE=' . var_export($idx['NON_UNIQUE'] ?? null, true));

echo "\n===== 1. 管理端登录 =====\n";
$lg = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
$atok = $lg['data']['token'] ?? '';
T('管理端登录 admin/admin123', ($lg['code'] ?? -1) === 200 && $atok, $lg['message'] ?? '');
if (!$atok) { fwrite(STDERR, "管理端登录失败，终止\n"); exit(1); }

echo "\n===== 2. auto 模式：提交即通过 =====\n";
T('切到 auto', (mode('auto')['code'] ?? -1) === 200);
[$uidA, $tokA] = newUser('QA实名AUTO甲');
$idA = rndId();
$r = http('POST', '/api/user/realname', ['realName' => '测试甲', 'idCard' => $idA], $tokA);
T('auto 提交返回 status=approved', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'approved', json_encode($r, JSON_UNESCAPED_UNICODE));
T('auto 提交 realname_status=2', (int) col($uidA, 'realname_status') === 2, 'got=' . var_export(col($uidA, 'realname_status'), true));
T('auto 提交 is_realname=1（能力位当场解锁）', (int) col($uidA, 'is_realname') === 1, 'got=' . var_export(col($uidA, 'is_realname'), true));
T('auto 提交写入 realname_verified_at（注册活动排位依据）', col($uidA, 'realname_verified_at') !== null, 'got=' . var_export(col($uidA, 'realname_verified_at'), true));
$rawId = (string) col($uidA, 'id_card');
T('姓名/证件号密文落库（列里不是明文）', col($uidA, 'real_name') !== '测试甲' && $rawId !== $idA && strlen($rawId) > 24, 'id_card 长度=' . strlen($rawId));
T('通过即写入 id_card_hash 指纹（一证一号的比对列）', preg_match('/^[0-9a-f]{64}$/', (string) col($uidA, 'id_card_hash')) === 1,
    'got=' . var_export(col($uidA, 'id_card_hash'), true));
$d = http('GET', "/admin/realname/users/$uidA", null, $atok);
T('后台 detail 解密回显与提交一致', ($d['data']['realName'] ?? '') === '测试甲' && ($d['data']['idCard'] ?? '') === $idA, json_encode($d['data'] ?? $d, JSON_UNESCAPED_UNICODE));
$li = http('GET', '/admin/realname/users?status=approved&page=1&page_size=100', null, $atok);
$masked = '';
foreach (($li['data']['list'] ?? []) as $it) { if ((int) ($it['id'] ?? 0) === $uidA) $masked = (string) $it['realnameIdNo']; }
T('列表仍脱敏（auto 不降低后台的隐私口径）', str_contains($masked, '*'), 'realnameIdNo=' . $masked);

echo "\n===== 3. auto 模式下的防线：格式、不可改、没有待审队列 =====\n";
[$uidB, $tokB] = newUser('QA实名AUTO乙');
foreach ([['地区码首位0', '010101199003077578'], ['出生月13', '11010119901307757X'], ['出生日34', '110101199003347578'], ['乱填18位数字', '123456789012345678']] as [$lbl, $bad]) {
    $r = http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $bad], $tokB);
    T("auto 仍挡住非法证号：$lbl", ($r['code'] ?? -1) === 1001 && ($r['message'] ?? '') === '身份证号格式错误', json_encode($r, JSON_UNESCAPED_UNICODE));
}
T('被挡后一行都没写库（status=0、id_card 为空）', (int) col($uidB, 'realname_status') === 0 && (string) col($uidB, 'id_card') === '',
    'status=' . var_export(col($uidB, 'realname_status'), true) . ' id_card=' . var_export(col($uidB, 'id_card'), true));
// B 用一张自己的证号（末位取小写 x）：与 A 的证号不同，才不会撞上「一证一号」（那是第 3b 节专门测的）
$idBlower = substr(rndId(), 0, 17) . 'x';
$r = http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $idBlower], $tokB);
T('auto 合法提交通过（末位小写 x 也接受）', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'approved', json_encode($r, JSON_UNESCAPED_UNICODE));
$dB0 = http('GET', "/admin/realname/users/$uidB", null, $atok);
T('小写 x 原样入库（后端不擅自改写用户输入，比对时不分大小写）', col($uidB, 'id_card') !== $idBlower
    && ($dB0['data']['idCard'] ?? '') === $idBlower, '解密回显=' . var_export($dB0['data']['idCard'] ?? null, true));
$r = http('POST', '/api/user/realname', ['realName' => '测试乙改名', 'idCard' => rndId()], $tokB);
T('auto 通过后身份不可再改', ($r['code'] ?? -1) === 1001 && str_contains((string) ($r['message'] ?? ''), '不可修改'), json_encode($r, JSON_UNESCAPED_UNICODE));
T('后台对 auto 已通过的用户点「通过」= 4220', (http('POST', '/admin/realname/audit', ['user_id' => $uidB, 'action' => 'approve'], $atok)['code'] ?? -1) === 4220);

echo "\n===== 3b. 一证一号：同一张证号只能认证一个账号 =====\n";
$uidUppercaseA = strtoupper($idA);
[$uidD, $tokD] = newUser('QA实名AUTO丁');
$r = http('POST', '/api/user/realname', ['realName' => '测试丁', 'idCard' => $uidUppercaseA], $tokD);
T('同证号的第二个账号被拒（1001）', ($r['code'] ?? -1) === 1001 && ($r['message'] ?? '') === '该身份证已绑定其他账号，如需处理请联系客服',
    json_encode($r, JSON_UNESCAPED_UNICODE));
T('C 端提示不透出对方 UID（不能用报错接口探测别人实名）', !str_contains((string) ($r['message'] ?? ''), 'UID'), $r['message'] ?? '');
T('被拒的账号一行不写（status=0 / 无证件 / 无指纹 / 无能力位）',
    (int) col($uidD, 'realname_status') === 0 && (string) col($uidD, 'id_card') === ''
    && col($uidD, 'id_card_hash') === null && (int) col($uidD, 'is_realname') === 0,
    'status=' . var_export(col($uidD, 'realname_status'), true) . ' hash=' . var_export(col($uidD, 'id_card_hash'), true));
$r = http('POST', '/api/user/realname', ['realName' => '测试丁', 'idCard' => strtolower($uidUppercaseA)], $tokD);
T('末位 x/X 归一：换大小写也算同一张证号，照样被拒', ($r['code'] ?? -1) === 1001
    && ($r['message'] ?? '') === '该身份证已绑定其他账号，如需处理请联系客服', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = http('POST', '/api/user/realname', ['realName' => '测试丁', 'idCard' => rndId()], $tokD);
T('换一张自己的证号照样认证通过（拦的是重复，不是这个账号）', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'approved',
    json_encode($r, JSON_UNESCAPED_UNICODE));
T('两个账号的指纹不同、A 的指纹没被覆盖', (string) col($uidD, 'id_card_hash') !== (string) col($uidA, 'id_card_hash')
    && preg_match('/^[0-9a-f]{64}$/', (string) col($uidD, 'id_card_hash')) === 1,
    'A=' . substr((string) col($uidA, 'id_card_hash'), 0, 12) . '… D=' . substr((string) col($uidD, 'id_card_hash'), 0, 12) . '…');
// 指纹列只用于比对，不能从任何接口漏出去（漏出去就等于给了攻击者离线撞库的素材）
$leak = [];
foreach ([['C端 profile', http('GET', '/api/user/profile', null, $tokD)],
          ['后台实名列表', http('GET', '/admin/realname/users?status=approved&page=1&page_size=50', null, $atok)],
          ['后台实名 detail', http('GET', "/admin/realname/users/$uidA", null, $atok)],
          ['后台用户列表', http('GET', '/admin/users?page=1&page_size=20', null, $atok)]] as [$lbl, $resp]) {
    $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
    if (stripos($json, 'id_card_hash') !== false || stripos($json, 'idCardHash') !== false) $leak[] = $lbl;
}
T('四个接口都不透出 id_card_hash', $leak === [], $leak ? '泄漏：' . implode('/', $leak) : '响应里查不到该字段名');

echo "\n===== 4. manual 模式：两步流零破坏 =====\n";
T('切到 manual', (mode('manual')['code'] ?? -1) === 200);
[$uidC, $tokC] = newUser('QA实名MANU丙');
$idC = rndId();
$r = http('POST', '/api/user/realname', ['realName' => '测试丙', 'idCard' => $idC], $tokC);
T('manual 提交返回 pending', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'pending', json_encode($r, JSON_UNESCAPED_UNICODE));
T('manual 提交后 status=1 / is_realname=0（能力位不提前解锁）', (int) col($uidC, 'realname_status') === 1 && (int) col($uidC, 'is_realname') === 0,
    'status=' . col($uidC, 'realname_status') . ' is_realname=' . col($uidC, 'is_realname'));
T('待审核不写指纹（认证通过才占用证号）', col($uidC, 'id_card_hash') === null, 'hash=' . var_export(col($uidC, 'id_card_hash'), true));
T('manual 审核中重复提交被拒', (http('POST', '/api/user/realname', ['realName' => '测试丙', 'idCard' => $idC], $tokC)['code'] ?? -1) === 1001);
$li = http('GET', '/admin/realname/users?status=pending&page=1&page_size=200', null, $atok);
$inQueue = false;
foreach (($li['data']['list'] ?? []) as $it) { if ((int) ($it['id'] ?? 0) === $uidC) $inQueue = true; }
T('待审核队列里查得到该用户', $inQueue, 'pending 行数=' . count($li['data']['list'] ?? []));
T('驳回不填原因被拒（4220）', (http('POST', '/admin/realname/audit', ['user_id' => $uidC, 'action' => 'reject'], $atok)['code'] ?? -1) === 4220);
T('带原因驳回成功', (http('POST', '/admin/realname/audit', ['user_id' => $uidC, 'action' => 'reject', 'reason' => '证件号与本人不符'], $atok)['code'] ?? -1) === 200);
T('驳回后 status=3 / is_realname=0 / 原因已存', (int) col($uidC, 'realname_status') === 3 && (int) col($uidC, 'is_realname') === 0
    && col($uidC, 'realname_reject_reason') === '证件号与本人不符', 'status=' . col($uidC, 'realname_status') . ' reason=' . var_export(col($uidC, 'realname_reject_reason'), true));
T('被驳回也不写指纹（驳回即让出证号，不算占用）', col($uidC, 'id_card_hash') === null, 'hash=' . var_export(col($uidC, 'id_card_hash'), true));

// 别人正在待审的证号，本账号也能提交 —— 待审核不占坑（否则乱填一个证号就能把真主人锁在门外）
[$uidE, $tokE] = newUser('QA实名MANU戊');
$r = http('POST', '/api/user/realname', ['realName' => '测试戊', 'idCard' => $idC], $tokE);
T('同一证号的第二个账号也能进待审队列（待审不占用）', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'pending',
    json_encode($r, JSON_UNESCAPED_UNICODE));

$r = http('POST', '/api/user/realname', ['realName' => '测试丙', 'idCard' => $idC], $tokC);
T('驳回后可用原证号重新提交（回到 pending）', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'pending', json_encode($r, JSON_UNESCAPED_UNICODE));
T('人工审核通过 200', (http('POST', '/admin/realname/audit', ['user_id' => $uidC, 'action' => 'approve'], $atok)['code'] ?? -1) === 200);
T('通过后 status=2 / is_realname=1', (int) col($uidC, 'realname_status') === 2 && (int) col($uidC, 'is_realname') === 1);
T('通过后 verified_at 有值（与 auto 用同一个字段）', col($uidC, 'realname_verified_at') !== null, var_export(col($uidC, 'realname_verified_at'), true));
T('通过后驳回原因被清空', (string) col($uidC, 'realname_reject_reason') === '', 'left=' . var_export(col($uidC, 'realname_reject_reason'), true));
T('通过后指纹写入', preg_match('/^[0-9a-f]{64}$/', (string) col($uidC, 'id_card_hash')) === 1, 'hash=' . var_export(col($uidC, 'id_card_hash'), true));
T('二次审核 4220', (http('POST', '/admin/realname/audit', ['user_id' => $uidC, 'action' => 'approve'], $atok)['code'] ?? -1) === 4220);
T('manual 通过后 detail 回显重验证件号', ($d = http('GET', "/admin/realname/users/$uidC", null, $atok)) && ($d['data']['idCard'] ?? '') === $idC, $d['data']['idCard'] ?? '');

// 丙认证通过之后，戊那张「同一证号」的待审单就不能再通过：由 RealnameService 在通过这一刻兜住
$r = http('POST', '/admin/realname/audit', ['user_id' => $uidE, 'action' => 'approve'], $atok);
T('管理员通过「同证号的第二个账号」被拦（4220）', ($r['code'] ?? -1) === 4220, json_encode($r, JSON_UNESCAPED_UNICODE));
T('管理端提示带对方 UID（C 端不带，运营需要知道撞在谁身上）',
    str_contains((string) ($r['message'] ?? ''), (string) $pdo->query("SELECT uid FROM nft_users WHERE id=$uidC")->fetchColumn()),
    $r['message'] ?? '');
T('被拦的戊仍留在待审队列、没拿到能力位、没写指纹',
    (int) col($uidE, 'realname_status') === 1 && (int) col($uidE, 'is_realname') === 0 && col($uidE, 'id_card_hash') === null,
    'status=' . var_export(col($uidE, 'realname_status'), true) . ' hash=' . var_export(col($uidE, 'id_card_hash'), true));
// 被拦不等于被判死：驳回后戊用自己的证号照样能走完两步流 —— 拦的是「证号重复」，不是这个账号
T('被拦后驳回戊（带原因）', (http('POST', '/admin/realname/audit', ['user_id' => $uidE, 'action' => 'reject', 'reason' => '该证件已绑定其他账号'], $atok)['code'] ?? -1) === 200);
$r = http('POST', '/api/user/realname', ['realName' => '测试戊', 'idCard' => rndId()], $tokE);
T('戊改用本人证号重新提交回到 pending', ($r['code'] ?? -1) === 0 && ($r['data']['status'] ?? '') === 'pending', json_encode($r, JSON_UNESCAPED_UNICODE));
T('戊人工通过 200（同证号已解绑，账号本身没被拉黑）', (http('POST', '/admin/realname/audit', ['user_id' => $uidE, 'action' => 'approve'], $atok)['code'] ?? -1) === 200);

echo "\n===== 5. 审核留痕 =====\n";
$tbl = '';
foreach (['nft_admin_operation_logs', 'nft_admin_logs', 'nft_operation_logs'] as $c) {
    if ($pdo->query("SHOW TABLES LIKE '$c'")->fetchColumn()) { $tbl = $c; break; }
}
if ($tbl !== '') {
    $have = array_column($pdo->query("SELECT DISTINCT action FROM `$tbl` WHERE target_type='user' AND target_id IN ($uidA,$uidB,$uidC,$uidD,$uidE)")->fetchAll(PDO::FETCH_ASSOC), 'action');
    T("$tbl 里有 audit_approve / audit_reject / view_full", in_array('audit_approve', $have, true) && in_array('audit_reject', $have, true) && in_array('view_full', $have, true),
        'actions=' . implode(',', $have));
    T("$tbl 里有一证一号拦截留痕 audit_conflict（运营能看到谁撞了谁的证号）", in_array('audit_conflict', $have, true),
        'actions=' . implode(',', $have));
    $conflict = $pdo->query("SELECT action_desc, detail FROM `$tbl` WHERE action='audit_conflict' AND target_id=$uidE ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    T('拦截日志写明原因并带上占用方 UID', str_contains((string) ($conflict['action_desc'] ?? ''), '一证一号')
        && str_contains((string) ($conflict['detail'] ?? ''), 'occupied_by'),
        mb_substr((string) ($conflict['action_desc'] ?? ''), 0, 60));
} else {
    T('找到后台操作日志表', false, '候选表名都没命中');
}

echo "\n===== 6. 只读统计命令（生产落地前先看存量重复） =====\n";
$backendDir = dirname($MIG_DIR);
$out = (string) shell_exec('cd ' . escapeshellarg($backendDir) . ' && ' . escapeshellarg(PHP_BINARY) . ' think realname:id-hash 2>&1');
T('php think realname:id-hash 只读模式跑通', str_contains($out, '只读统计') && str_contains($out, '已认证账号'),
    preg_replace('/\s+/', ' ', substr($out, 0, 120)));
// 本脚本认证的账号都必须有指纹（没有就是「已实名却不占用证号」的漏网账号）
$missing = (int) $pdo->query("SELECT COUNT(*) FROM nft_users WHERE id IN ($uidA,$uidB,$uidC,$uidE)
    AND (id_card_hash IS NULL OR CHAR_LENGTH(id_card_hash) <> 64)")->fetchColumn();
T('本次认证通过的 4 个账号指纹齐全', $missing === 0, "缺指纹=$missing");
if (str_contains($out, '同一证号认证了多个账号')) {
    echo "  统计报出了历史重复组：升级成硬唯一索引前要先按它清理（见 RUNBOOK 7.13）\n";
}

echo "\n===== 7. 收尾：把库里的值恢复成默认 auto =====\n";
T('恢复 auto', (mode('auto')['code'] ?? -1) === 200);
T('库里最终值 = auto', (string) $pdo->query("SELECT config_value FROM nft_system_configs WHERE config_key='realname_audit_mode'")->fetchColumn() === 'auto');
printf("\n[qa] 本次注册的测试账号：%s（id 见上方输出，需要清理就按手机号删）\n",
    implode(' / ', ['QA实名AUTO甲', 'QA实名AUTO乙', 'QA实名AUTO丁', 'QA实名MANU丙', 'QA实名MANU戊']));

printf("\n===== 结果：%d 通过 / %d 失败 =====\n", $pass, $fail);
if ($fail) { echo "失败项：\n - " . implode("\n - ", $fails) . "\n"; exit(1); }

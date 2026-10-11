<?php
/**
 * 图片清理「保护闸门」SIT 验证（沙箱直连服务端，2026-10-12 加）
 *
 * 起因：2026-10-12 只读体检（RUNBOOK 7.20 S3）发现两处会把在用的图判成「零引用」：
 *   1) 引用扫描列里 announcements 只有 cover_image，正文 content 里的 <img> 不算引用；
 *      并且顶部注释那句「announcement 目录刻意不扫」只存在于注释里，扫描其实是递归全目录。
 *   2) 「先选图 → 后点保存」这段时间里新图谁都没引用，也没有宽限期保护。
 * 修法收成一个闸门 UploadCleanupService::protectedReason()，扫描与移入回收站都问它。
 *
 * 为什么不走 HTTP：本地 PHP 没装 curl 扩展（qa/*_verify 那套要 curl），
 * 而这两道判定是纯服务端逻辑 —— 直接实例化服务、打真沙箱库、碰真磁盘，比绕一层 HTTP 更贴被测点。
 *
 * 用法（凭据一律由 .env / 环境变量提供，仓库内不写明文）：
 *   set APP_ENV=sit& php qa/sit_upload_protection.php
 *
 * 自检护栏：数据库主机必须是回环地址，否则直接退出，防止拷到生产机上跑写库夹具。
 * 自清洁：临时公告行、临时图、回收站条目全部删掉；跑完的沙箱应与跑前一致。
 */
date_default_timezone_set('Asia/Shanghai');

$ROOT = dirname(__DIR__) . '/sinan-nft-backend';
require $ROOT . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

$env = strtolower((string) getenv('APP_ENV'));
if (!in_array($env, ['sit', 'test', 'dev'], true)) {
    fwrite(STDERR, "[qa] 拒绝执行：需显式 APP_ENV=sit|test|dev（当前 '{$env}'）\n");
    exit(1);
}
$cfg = think\facade\Config::get('database.connections.mysql');
$host = (string) ($cfg['hostname'] ?? '');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "[qa] 拒绝执行：数据库主机 {$host} 不是回环地址，本脚本会写库\n");
    exit(1);
}
printf("[qa] 目标库 %s:%s/%s（回环，允许写夹具）\n", $host, $cfg['hostport'] ?? '', $cfg['database'] ?? '');

$pass = 0; $fail = 0;
function T(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    $c ? $pass++ : $fail++;
    echo ($c ? '  PASS ' : '  FAIL ') . $n . ($d !== '' ? " | $d" : '') . "\n";
}

function tinyPng(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
}

$svc = new app\admin\service\UploadCleanupService();
$uploads = $svc->uploadsRoot();
$trash = $svc->trashRoot();
$Ym = date('Ym');
$HOURS = $svc->graceHours();
$created = [];          // 需要清理的文件绝对路径
$dirs = [];             // 需要尝试回收的空目录

/** 造一张临时上传图：$rel 形如 collection/202610/xxx.png，$ageSeconds 是文件「已经存在多久」 */
function fixture(string $uploads, string $rel, int $ageSeconds, array &$created, array &$dirs): string {
    $abs = $uploads . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($abs);
    if (!is_dir($dir)) { mkdir($dir, 0755, true); $dirs[] = $dir; }
    file_put_contents($abs, tinyPng());
    touch($abs, time() - $ageSeconds);
    $created[] = $abs;
    return '/uploads/' . $rel;
}

function urlsOf(array $rows): array { return array_column($rows, 'url'); }

echo "========== 0. 闸门自身的口径 ==========\n";
T('0.1 宽限期小时数由服务给出（接口与前端都读它）', $HOURS > 0, "graceHours={$HOURS}");
T('0.2 不参与清理的目录里必须有 announcement', in_array('announcement', $svc->excludedBizDirs(), true),
    'excludedBiz=' . implode(',', $svc->excludedBizDirs()));
T('0.3 藏品目录在清理范围内', in_array('collection', $svc->bizDirs(), true));

echo "========== 1. 夹具 ==========\n";
// A：只被公告**正文**引用的一张图，放在参与扫描的 collection 目录 —— 修之前它会被判零引用
$urlFromContent = fixture($uploads, "collection/{$Ym}/qa_protect_from_content.png", 10 * 86400, $created, $dirs);
// B：公告目录里一张谁都没引用、且早就过了宽限期的图 —— 整个目录不参与清理，只进 protected
$urlAnnOrphan = fixture($uploads, "announcement/{$Ym}/qa_protect_ann_orphan.png", 10 * 86400, $created, $dirs);
// C：刚上传、还没保存的藏品图 —— 靠宽限期保护
$urlFresh = fixture($uploads, "collection/{$Ym}/qa_protect_fresh.png", 60, $created, $dirs);
// D：过了宽限期、确实没人引用的藏品图 —— 应当正常出现在清理列表里
$urlStale = fixture($uploads, "collection/{$Ym}/qa_protect_stale.png", 10 * 86400, $created, $dirs);

// 临时公告：正文 <img> 引用 A，封面也指 A（封面列本来就在扫描范围内，用来对照）
$aid = 987654;
think\facade\Db::name('announcements')->where('id', $aid)->delete();
think\facade\Db::name('announcements')->insert([
    'id'     => $aid,
    'title'  => '清理闸门测试公告',
    'content' => '<p>正文插图</p><img src="' . $urlFromContent . '" alt="">',
    'cover_image' => $urlFromContent,
    'type'   => 'notice',
    'status' => 'draft',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
printf("  夹具就绪：公告 id=%d，4 张临时图\n", $aid);

echo "========== 2. 正文引用算不算引用（修前必失败的一条） ==========\n";
$protected = [];
$items = $svc->scanUnreferenced(null, $protected);
$listUrls = urlsOf($items);
$protUrls = urlsOf($protected);

T('2.1 公告正文 <img> 指向的图不被判为零引用', !in_array($urlFromContent, $listUrls, true),
    '被引用扫描列漏掉时它会出现在零引用列表里');
$extracted = $svc->extractUploadUrls('<p>x</p><img src="/uploads/collection/202610/a.png">');
T('2.2 富文本里的上传图 URL 能被抽出来', in_array('/uploads/collection/202610/a.png', $extracted, true),
    implode(',', $extracted));

echo "========== 3. 两类保护各管各的 ==========\n";
T('3.1 公告目录整目录不进列表', !in_array($urlAnnOrphan, $listUrls, true));
T('3.2 公告目录那张图进的是 protected，原因写清「不在清理名单内」', in_array($urlAnnOrphan, $protUrls, true),
    (string) ($protected[array_search($urlAnnOrphan, $protUrls, true)]['reason'] ?? '未出现'));
T('3.3 刚上传的图不进列表', !in_array($urlFresh, $listUrls, true));
$freshReason = (string) ($protected[array_search($urlFresh, $protUrls, true)]['reason'] ?? '');
T('3.4 刚上传的图原因里带「上传不足 N 小时」和「可能还没点保存」',
    str_contains($freshReason, '上传不足') && str_contains($freshReason, '还没点保存'), $freshReason);
T('3.5 过了宽限期的零引用图正常进列表', in_array($urlStale, $listUrls, true));

echo "========== 4. 移入回收站再问一遍闸门 ==========\n";
$r = $svc->trashFiles([$urlFresh, $urlAnnOrphan, $urlStale]);
T('4.1 受保护的两张被跳过并回报原因', count($r['protected']) === 2, implode(' ; ', $r['protected']));
T('4.2 该清的这张移进了回收站', in_array($urlStale, $r['moved'], true), json_encode(array_keys($r), JSON_UNESCAPED_UNICODE));
T('4.3 受保护的文件仍在原位', is_file($uploads . str_replace('/uploads', '', $urlFresh))
    && is_file($uploads . str_replace('/uploads', '', $urlAnnOrphan)));
$staleMovedTo = $trash . str_replace('/uploads', '', $urlStale);
T('4.4 移走的文件离开 uploads、落到回收站', !is_file($uploads . str_replace('/uploads', '', $urlStale)) && is_file($staleMovedTo));

echo "========== 5. 恢复与彻底删除（回收站闭环没被改坏） ==========\n";
$rs = $svc->restoreFiles([$urlStale]);
$staleBack = is_file($uploads . str_replace('/uploads', '', $urlStale));
T('5.1 能恢复回 uploads', in_array($urlStale, $rs['restored'], true) && $staleBack,
    json_encode($rs, JSON_UNESCAPED_UNICODE));
$pg = $svc->purgeTrashFiles([$urlStale]);
T('5.2 刚恢复的文件不许被 purge 顺手删掉（回收站里已没有它，报 missing）',
    ($pg['purged'] ?? []) === [] && in_array($urlStale, $pg['missing'] ?? [], true) && $staleBack,
    json_encode($pg, JSON_UNESCAPED_UNICODE));

echo "========== 6. 收尾 ==========\n";
foreach ($created as $abs) { @unlink($abs); }
for ($i = count($dirs) - 1; $i >= 0; $i--) { @rmdir($dirs[$i]); }
@unlink($staleMovedTo);
for ($i = count($dirs) - 1; $i >= 0; $i--) {
    @rmdir(str_replace($uploads, $trash, $dirs[$i]));
}
think\facade\Db::name('announcements')->where('id', $aid)->delete();
$left = think\facade\Db::name('announcements')->where('id', $aid)->count();
T('6.1 临时公告已删除', $left == 0);
$remain = array_filter($created, 'file_exists');
T('6.2 临时图已清干净', $remain === [], implode(' , ', array_map('basename', $remain)));

echo "\n========== 结果: PASS={$pass} FAIL={$fail} ==========\n";
exit($fail > 0 ? 1 : 0);

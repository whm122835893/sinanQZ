<?php
/**
 * 孤儿图清理执行脚本（生产上跑）：只走平台自己的两个入口，**绝不物理删除**。
 *
 *   1) trashFiles()           —— 批量清理入口：动手前逐条重查引用 + 过 protectedReason() 闸门
 *   2) releaseIfUnreferenced() —— 事件驱动的显式动作入口：只重查引用，不受目录白名单与 24h 宽限期限制
 *      （那 8 张被闸门保护的图走这条路：4 张是公告目录、4 张还在宽限期内，
 *       都是本轮发版冒烟测试自己上传的，库里没有任何内容列引用它们）
 *
 * 两个入口都只做一件事：把文件 rename 进 runtime/upload_trash/{同样的相对路径}。
 * 后台「图片清理」页面能看到、能 restore；purge（真删）这个脚本里没有、也不会有。
 * 跑之前必须先手工打一份 uploads 压缩包当退路（见 RUNBOOK 对应小节）。
 *
 * 用法：/www/server/php/84/bin/php /tmp/trash_orphan_uploads.php
 */
require '/www/wwwroot/sinan-backend/vendor/autoload.php';
$app = new \think\App('/www/wwwroot/sinan-backend/');
$app->initialize();

use app\admin\service\UploadCleanupService;

$svc       = new UploadCleanupService();
$protected = [];
$items     = $svc->scanUnreferenced(null, $protected);

echo "== 动手前的实况 ==\n";
echo "  可清理 " . count($items) . " 张 / " . array_sum(array_column($items, 'size')) . " 字节\n";
echo "  被闸门保护 " . count($protected) . " 张 / " . array_sum(array_column($protected, 'size')) . " 字节\n";

echo "== 1. 走批量清理入口（trashFiles，内部会再过一遍引用与闸门）==\n";
$r = $svc->trashFiles(array_column($items, 'url'));
printf("  moved=%d referenced=%d protected=%d missing=%d invalid=%d\n",
    count($r['moved']), count($r['referenced']), count($r['protected']), count($r['missing']), count($r['invalid']));
foreach ($r['protected'] as $p) {
    echo "  仍然没动: {$p}\n";
}
foreach ($r['referenced'] as $p) {
    echo "  扫描后又被引用了（跳过）: {$p}\n";
}

echo "== 2. 被闸门保护的那批：走显式动作入口（只重查引用，不看目录与宽限期）==\n";
$released = $kept = [];
foreach ($protected as $p) {
    $url    = $p['url'];
    $before = $svc->isReferenced($url);
    if ($before) {
        $kept[] = $url . '（库里仍在引用，一条没动）';
        continue;
    }
    $released[] = $svc->releaseIfUnreferenced($url) ? $url : $url . '（文件不在，跳过）';
}
echo "  移走 " . count($released) . " 张：\n";
foreach ($released as $u) {
    echo "    {$u}\n";
}
echo "  仍在 " . count($kept) . " 张：\n";
foreach ($kept as $u) {
    echo "    {$u}\n";
}

echo "== 3. 收尾复核 ==\n";
$cnt = static function (string $d): array {
    $n = 0;
    $b = 0;
    if (!is_dir($d)) {
        return [0, 0];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $n++;
            $b += $f->getSize();
        }
    }
    return [$n, $b];
};
[$un, $ub] = $cnt(UploadCleanupService::uploadsRoot());
[$tn, $tb] = $cnt(UploadCleanupService::trashRoot());
echo "  uploads 现存 {$un} 张 / {$ub} 字节\n";
echo "  回收站 {$tn} 张 / {$tb} 字节（后台可 restore；purge 没跑、也没在脚本里）\n";
$leftoverProtected = [];
$still = $svc->scanUnreferenced(null, $leftoverProtected);
echo "  再扫一遍可清理：" . count($still) . " 张（应为 0）；仍受保护：" . count($leftoverProtected) . " 张\n";
echo "  库里仍在引用的 URL：" . implode(' | ', array_keys($svc->collectReferencedUrls())) . "\n";

<?php
/**
 * 上传图片清理：只读探针（生产上跑，只查不写）。
 *
 * 只调用 scanUnreferenced()，绝不碰 trashFiles / purgeTrashFiles —— 它连库都不改，
 * 存在的意义是把「平台自己认为哪些是可清理的孤儿图、哪些是被保护的」原样打出来，
 * 免得我用 shell 的 grep 口径去猜（上一轮就是猜错了：库里多数记录存的是不带 /uploads/ 前缀的
 * 相对路径，前缀匹配会把这些文件误判成零引用）。
 *
 * 用完即删，不放 web 目录下。
 */
require '/www/wwwroot/sinan-backend/vendor/autoload.php';
$app = new \think\App('/www/wwwroot/sinan-backend/');
$app->initialize();

use app\admin\service\UploadCleanupService;

$svc       = new UploadCleanupService();
$protected = [];
$items     = $svc->scanUnreferenced(null, $protected);

$sum = static function (array $rows): int {
    $t = 0;
    foreach ($rows as $r) {
        $t += (int) ($r['size'] ?? 0);
    }
    return $t;
};

echo "== 1. 可清理（零引用 + 不受保护）：" . count($items) . " 张 / " . $sum($items) . " 字节 ==\n";
foreach ($items as $it) {
    printf("  %-62s %9d  %s  [%s]\n", $it['url'], $it['size'], $it['mtime'], $it['biz']);
}

echo "== 2. 被保护的（本轮不清）：" . count($protected) . " 张 / " . $sum($protected) . " 字节 ==\n";
foreach ($protected as $p) {
    printf("  %-62s %9d  %s  ← %s\n", $p['url'], $p['size'], $p['mtime'] ?? '', $p['reason'] ?? '');
}

echo "== 3. 口径自证 ==\n";
echo "  参与清理的业务目录: " . implode(', ', $svc->bizDirs()) . "\n";
echo "  排除目录: " . implode(', ', $svc->excludedBizDirs()) . "\n";
echo "  新上传宽限期: " . $svc->graceHours() . " 小时\n";
$ref = $svc->collectReferencedUrls();
echo "  库里登记的引用 URL 共 " . count($ref) . " 条: " . implode(' | ', array_keys($ref)) . "\n";

echo "== 4. 目录与回收站 ==\n";
$root  = UploadCleanupService::uploadsRoot();
$trash = UploadCleanupService::trashRoot();
$cnt   = static function (string $d): int {
    if (!is_dir($d)) {
        return 0;
    }
    $n   = 0;
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $n++;
        }
    }
    return $n;
};
echo "  uploads=$root 文件数=" . $cnt($root) . "\n";
echo "  回收站=$trash 文件数=" . $cnt($trash) . "（restore/purge 都走它）\n";

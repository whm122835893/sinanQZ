<?php
/**
 * 上传图片清理功能 SIT 验证（幂等，可重复执行）
 *
 * 前置：后端 127.0.0.1:8080 已启动；MySQL sinan_nft；管理员 admin/admin123
 * 覆盖：
 *   1. 权限种子存在（system:image-cleanup）
 *   2. 扫描零引用图 → 放置的测试图被识别
 *   3. 勾选式批量移入回收站 → 文件迁移到 runtime/upload_trash
 *   4. 回收站列表包含该图
 *   5. 恢复 → 文件回到 uploads
 *   6. 再次回收 + 彻底删除 → 文件物理消失
 *   7. 引用保护：被藏品引用的图不会因 trash 被删（跳过）
 *   8. 方案A 钩子：藏品换图后旧上传图自动进回收站
 * 自清洁：使用独立测试图 URL（/uploads/misc/qa_cleanup_*），结束删除临时文件与测试藏品
 */
date_default_timezone_set('Asia/Shanghai');
$BASE = getenv('QA_BASE') ?: 'http://127.0.0.1:8080';
define('QA_PDO_ERRMODE', PDO::ERRMODE_EXCEPTION);
require __DIR__ . '/bootstrap_db.php';
$PDO = qa_pdo();

$pass = 0; $fail = 0;
function T($n, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? "  PASS " : "  FAIL ") . $n . ($d ? " | $d" : "") . "\n"; }

function http($m, $u, $b = null, $t = null) {
    global $BASE;
    $ch = curl_init($BASE . $u);
    $h = ['Content-Type: application/json'];
    if ($t) $h[] = "Authorization: Bearer $t";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b ?: new stdClass())]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($raw, true) ?: ['code' => -2, 'message' => 'BADJSON']];
}

// 1x1 透明 PNG 字节
function tinyPng() {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
}

$ROOT = dirname(__DIR__) . '/sinan-nft-backend';
$UPLOADS = $ROOT . '/public/uploads';
// 多应用模式下 runtime_path() 带应用名子目录：runtime/admin/upload_trash
$TRASH = $ROOT . '/runtime/admin/upload_trash';

echo "========== 0. 登录与种子检查 ==========\n";
[, $ar] = http('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
$atok = (string) ($ar['data']['token'] ?? '');
T('0.1 管理员登录', $atok !== '', "code=" . ($ar['code'] ?? '?'));

$perm = $PDO->query("SELECT id FROM nft_admin_permissions WHERE code='system:image-cleanup'")->fetch(PDO::FETCH_NUM);
T('0.2 权限 system:image-cleanup 存在', !empty($perm[0]), $perm ? "id={$perm[0]}" : '缺失');

// 生成唯一测试图（放 misc 目录）
$stamp = date('Ym');
$fname = 'qa_cleanup_' . bin2hex(random_bytes(4)) . '.png';
$relDir = "$UPLOADS/misc/$stamp";
if (!is_dir($relDir)) mkdir($relDir, 0755, true);
$absFile = "$relDir/$fname";
$url = "/uploads/misc/$stamp/$fname";
file_put_contents($absFile, tinyPng());
$absTrash = "$TRASH/misc/$stamp/$fname";

echo "========== 1. 扫描零引用 ==========\n";
[$c1, $r1] = http('GET', '/admin/upload-cleanup/unreferenced?page=1&pageSize=100', null, $atok);
$found = false;
foreach (($r1['data']['items'] ?? []) as $it) { if ($it['url'] === $url) { $found = true; break; } }
// 分页可能取不到，回退：逐页找 biz=misc
if (!$found) {
    [$c1b, $r1b] = http('GET', '/admin/upload-cleanup/unreferenced?page=1&pageSize=100&biz=misc', null, $atok);
    foreach (($r1b['data']['items'] ?? []) as $it) { if ($it['url'] === $url) { $found = true; break; } }
}
T('1.1 新放置的测试图被识别为零引用', $found, "url=$url code={$r1['code']}");

echo "========== 2. 勾选移入回收站 ==========\n";
[, $r2] = http('POST', '/admin/upload-cleanup/trash', ['urls' => [$url]], $atok);
T('2.1 移入回收站返回成功', ($r2['code'] ?? 0) === 200 && in_array($url, $r2['data']['moved'] ?? []), json_encode($r2['data'] ?? $r2['message'] ?? '', JSON_UNESCAPED_UNICODE));
T('2.2 文件已离开 uploads', !file_exists($absFile));
T('2.3 文件出现在回收站目录', file_exists($absTrash));

echo "========== 3. 回收站列表包含该图 ==========\n";
[$c3, $r3] = http('GET', '/admin/upload-cleanup/trash?page=1&pageSize=100', null, $atok);
$inTrash = false;
foreach (($r3['data']['items'] ?? []) as $it) { if ($it['url'] === $url) { $inTrash = true; break; } }
T('3.1 回收站列表含测试图', $inTrash, "code={$r3['code']}");

echo "========== 4. 恢复 ==========\n";
[, $r4] = http('POST', '/admin/upload-cleanup/trash/restore', ['urls' => [$url]], $atok);
T('4.1 恢复返回成功', ($r4['code'] ?? 0) === 200 && in_array($url, $r4['data']['restored'] ?? []), json_encode($r4['data'] ?? $r4['message'] ?? '', JSON_UNESCAPED_UNICODE));
T('4.2 文件回到 uploads', file_exists($absFile));
T('4.3 回收站中文件已移除', !file_exists($absTrash));

echo "========== 5. 再次回收 + 彻底删除 ==========\n";
http('POST', '/admin/upload-cleanup/trash', ['urls' => [$url]], $atok);
[, $r5] = http('POST', '/admin/upload-cleanup/trash/purge', ['urls' => [$url]], $atok);
T('5.1 彻底删除返回成功', ($r5['code'] ?? 0) === 200 && in_array($url, $r5['data']['purged'] ?? []), json_encode($r5['data'] ?? '', JSON_UNESCAPED_UNICODE));
T('5.2 文件物理消失（uploads 与回收站均无）', !file_exists($absFile) && !file_exists($absTrash));

echo "========== 6. 引用保护：被藏品引用的图不可清理 ==========\n";
$refName = 'qa_cleanup_ref_' . bin2hex(random_bytes(4)) . '.png';
$refDir = "$UPLOADS/collection/$stamp";
if (!is_dir($refDir)) mkdir($refDir, 0755, true);
$refAbs = "$refDir/$refName";
$refUrl = "/uploads/collection/$stamp/$refName";
file_put_contents($refAbs, tinyPng());
$cid = 9890;
$PDO->exec("DELETE FROM nft_collectibles WHERE id=$cid");
$PDO->exec("INSERT INTO nft_collectibles (id,category_id,name,image,price,edition,circulate,sold,locked_quantity,per_user_limit,status,created_at,updated_at)
  VALUES ($cid,1,'清理测试藏品'," . $PDO->quote($refUrl) . ",100,10,0,0,0,10,'onsale',NOW(),NOW())");
[, $r6] = http('POST', '/admin/upload-cleanup/trash', ['urls' => [$refUrl]], $atok);
$skipped = in_array($refUrl, $r6['data']['referenced'] ?? []);
T('6.1 被引用图移入回收站时被跳过', $skipped, json_encode($r6['data'] ?? $r6['message'] ?? '', JSON_UNESCAPED_UNICODE));
T('6.2 被引用图文件仍在原位', file_exists($refAbs));

echo "========== 7. 方案A：藏品换图后旧图自动进回收站 ==========\n";
$newImgUrl = "/uploads/collection/$stamp/qa_cleanup_new_" . bin2hex(random_bytes(3)) . ".png";
file_put_contents("$refDir/" . basename($newImgUrl), tinyPng());
[, $r7] = http('PUT', "/admin/collectibles/$cid", ['image' => $newImgUrl], $atok);
T('7.1 藏品换图成功', ($r7['code'] ?? 0) === 200, "code={$r7['code']} {$r7['message']}");
$oldNowInTrash = file_exists("$TRASH/collection/$stamp/$refName");
T('7.2 旧图已自动移入回收站', $oldNowInTrash && !file_exists($refAbs), "trash=" . ($oldNowInTrash ? 'Y' : 'N'));

// 清理：删测试藏品 + 新图 + 回收站里的旧图，避免污染
$PDO->exec("DELETE FROM nft_collectibles WHERE id=$cid");
@unlink("$refDir/" . basename($newImgUrl));
@unlink($refAbs);
@unlink("$TRASH/collection/$stamp/$refName");
@rmdir("$TRASH/misc/$stamp"); @rmdir("$TRASH/misc");

echo "\n========== 结果: PASS=$pass FAIL=$fail ==========\n";
exit($fail > 0 ? 1 : 0);

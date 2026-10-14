<?php
declare(strict_types=1);

// ============================================================================
// 公告「类型默认头图」出厂素材生成器（2026-10-14，需求见 RUNBOOK §7.36）
//
// 用法：
//   php scripts/make_notice_banners.php <AI素材目录> <输出目录>
//   php scripts/make_notice_banners.php /path/to/art ./public/uploads/notice-default
//
// 素材从哪来：四个子分类各一张 21:9 无文字底图，由 AI 生成（提示词原文记在 RUNBOOK §7.36，
//   换图/重做时照着提就行）。**故意不让 AI 直接写中文字** —— 生成式模型画汉字经常缺笔少画，
//   而这张图要挂在正式公告顶上，错一个笔画就是平台级事故。所以文字由本脚本用系统字体
//   （黑体 simhei.ttf + Arial）画上去：字形一定正确、四个子分类风格一定一致、以后改文案不用重画。
//
// 输出：announcement-{subtype}.webp，宽 1280（C 端详情页那张卡片宽约 311 CSS px，3 倍屏也够）
//
// 为什么顺手裁掉底部 90px：AI 出图右下角带「Qoder AI 生成」字样的水印条，裁掉最省事，
//   裁完的比例（2560×990 ≈ 2.6:1）反而更接近参考稿的横幅比例。
//
// 只读输入、只写输出目录，不碰数据库、不碰 git。
// ============================================================================

if (PHP_SAPI !== 'cli') {
    exit("本脚本只能在命令行下运行\n");
}

$root = dirname(__DIR__);
require $root . '/app/service/AnnouncementCover.php';

$artDir   = rtrim($argv[1] ?? '', '/\\');
$outDir   = rtrim($argv[2] ?? '', '/\\');
if ($artDir === '' || $outDir === '' || !is_dir($artDir)) {
    exit("用法: php scripts/make_notice_banners.php <AI素材目录> <输出目录>\n");
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    exit("输出目录创建失败: {$outDir}\n");
}

$cnFont    = 'C:\\Windows\\Fonts\\simhei.ttf';   // 中文标题（黑体，笔画不会错）
$latinFont = 'C:\\Windows\\Fonts\\arial.ttf';    // 英文小标
foreach ([$cnFont, $latinFont] as $f) {
    if (!is_file($f)) {
        exit("缺字体文件: {$f}\n");
    }
}

/** 每个子分类的英文小标与素材文件名前缀（中文名与主色从 AnnouncementCover 取，不在此重复） */
$SPEC = [
    'activity'      => ['en' => 'ACTIVITY NOTICE',       'art' => 'notice-activity-art'],
    'compose'       => ['en' => 'COMPOSITION NOTICE',    'art' => 'notice-compose-art'],
    'consignment'   => ['en' => 'CONSIGNMENT NOTICE',    'art' => 'notice-consignment-art'],
    'operation'     => ['en' => 'OPERATIONS ANNOUNCEMENT', 'art' => 'notice-operation-art'],
];

$W      = 2560;   // 素材宽（画布按素材原尺寸走，这里只是断言用）
$CROP   = 90;     // 底部裁掉的水印条高度
$OUT_W  = 1280;   // 交付宽度
$MARGIN = 170;    // 左边距

/**
 * 字距拉开的大写英文（GD 没有 letter-spacing，逐字画）
 *
 * 按「字符」切而不是按「字节」切：文案里有 · 这种两字节字符，用 strlen/[$i] 逐字节画
 * 会把它劈成两个非法字节序列，出来就是乱码方块。
 *
 * @return int 结束时的 x
 */
function drawTracked($img, string $text, int $x, int $y, int $size, int $color, string $font, float $tracking): int
{
    foreach (mb_str_split($text) as $ch) {
        imagettftext($img, $size, 0, $x, $y, $color, $font, $ch);
        $box = imagettfbbox($size, 0, $font, $ch);
        $x  += (int) ceil(($box[2] - $box[0]) + $tracking);
    }
    return $x;
}

$made = [];
foreach ($SPEC as $subtype => $spec) {
    $label = \app\service\AnnouncementCover::SUBTYPES[$subtype]['label'];
    $hits  = glob($artDir . '/' . $spec['art'] . '*.png') ?: [];
    if (!$hits) {
        fwrite(STDERR, "跳过 {$subtype}：素材目录里找不到 {$spec['art']}*.png\n");
        continue;
    }
    $src = imagecreatefromstring((string) file_get_contents($hits[0]));
    if ($src === false) {
        fwrite(STDERR, "跳过 {$subtype}：素材读不出来\n");
        continue;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcH <= $CROP + 200) {
        fwrite(STDERR, "跳过 {$subtype}：素材太矮（{$srcH}px），裁不掉水印\n");
        continue;
    }

    // ---- 裁掉右下角水印条 ----
    $canvas = imagecrop($src, ['x' => 0, 'y' => 0, 'width' => $srcW, 'height' => $srcH - $CROP]);
    $H = imagesy($canvas);

    // ---- 左侧压暗：保证白字在任何一张底图上都读得清（四张的左半明暗差得很多）----
    $steps = (int) round($srcW * 0.62);
    for ($x = 0; $x < $steps; $x++) {
        $opacity = 0.32 * (1 - $x / $steps);                 // 左边缘 32% 黑 → 62% 宽度处归零
        $c = imagecolorallocatealpha($canvas, 0, 0, 0, (int) round(127 * (1 - $opacity)));
        imageline($canvas, $x, 0, $x, $H, $c);
    }

    $white  = imagecolorallocatealpha($canvas, 255, 255, 255, 0);
    $soft   = imagecolorallocatealpha($canvas, 255, 255, 255, 42);  // ≈ 67% 不透明
    $faint  = imagecolorallocatealpha($canvas, 255, 255, 255, 66);  // ≈ 48% 不透明

    // ---- 文字 ----
    // 字号是按「C 端详情页里这张图实际显示多宽」倒推的：卡片净宽约 311 CSS px，
    // 画布 2560 → 显示时缩到约 1/8.2，所以画布上 190px 的标题在手机上约 23px、46px 的英文小条约 5.6px。
    // 换交付宽度或换卡片宽度时，这几个数要跟着重算，别只看设计稿上的观感。
    drawTracked($canvas, 'SINAN · DIGITAL ART', $MARGIN, (int) round($H * 0.20), 40, $faint, $latinFont, 8);
    drawTracked($canvas, $spec['en'], $MARGIN, (int) round($H * 0.46), 46, $soft, $latinFont, 10);
    imagettftext($canvas, 190, 0, $MARGIN - 8, (int) round($H * 0.78), $white, $cnFont, $label);

    // ---- 缩到交付宽度后出 webp ----
    $scale = $OUT_W / $srcW;
    $small = imagescale($canvas, $OUT_W, (int) round($H * $scale));
    $outH  = imagesy($small);
    $out   = $outDir . '/announcement-' . $subtype . '.webp';
    imagewebp($small, $out, 82);

    $made[] = sprintf('%-12s %s  %dx%d  %d KB', $subtype, $label, $OUT_W, $outH,
        (int) round(filesize($out) / 1024));
}

echo implode("\n", $made), "\n";
echo count($made) === count($SPEC)
    ? "四张齐了\n"
    : "只出了 " . count($made) . " 张，检查上面跳过的原因\n";

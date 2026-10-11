<?php
declare(strict_types=1);

namespace tests\unit;

use app\admin\controller\UploadController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 后台图片上传闸门（UploadController::image）
 *
 * 起因：2026-10-11 你报「现在创建藏品也上传不了图片了」。生产日志里当天 13 次上传只有 2 次成功，
 * 11 次被内容检查拒掉、零文件落盘，拒绝话术就是「文件扩展名与实际内容不一致」那两条。
 * 排查结论：扩展名那条纯属误伤（落盘文件名一直是服务端按嗅探类型生成的，客户端后缀从没参与过存储），
 * 而「不是有效图片」多半是 iPhone 的 HEIC 或 iCloud 还没下载下来的占位文件 —— 话术没告诉运营下一步该干什么。
 *
 * 于是口径改成（你选的「后端放宽 + 前端自动转 JPEG」）：
 *   1. 后端不再比对客户端文件名后缀；
 *   2. 四格式白名单与 5MB 一个数都不动（放宽的只是「后缀长得不一样」这件事）；
 *   3. 拒绝话术带真实类型与体积，HEIC / 读不出图各给一条照着做完就的路；
 *   4. HEIC 由后台前端在浏览器里转成 JPEG 再传（见 sinan-admin/src/api/index.js 的 uploadImage）。
 *
 * 这里只测不依赖 HTTP 的纯函数与源码事实：真上传要框架与临时目录，留给 --testsuite api。
 */
class UploadImageGateTest extends TestCase
{
    /** 反射读私有常量：ALLOWED / MAX_SIZE 是闸门本体，不该为了测试改成 public */
    private static function constOf(string $name)
    {
        $ref = new ReflectionClass(UploadController::class);
        $const = $ref->getReflectionConstant($name);
        self::assertNotFalse($const, "UploadController 应当还有常量 {$name}");
        return $const->getValue();
    }

    private static function source(): string
    {
        $file = dirname(__DIR__, 2) . '/app/admin/controller/UploadController.php';
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    // ------------------------------------------------ 四格式白名单（没动）

    public function test_白名单仍然只有那四种(): void
    {
        $allowed = self::constOf('ALLOWED');
        $this->assertSame(
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
            $allowed,
            '「放宽」只放宽后缀比对，别顺手往白名单里加格式：HEIC 之类是前端转成 JPEG 后进来的'
        );
    }

    #[DataProvider('mimeCases')]
    public function test_类型判定只看服务端嗅探结果(string $mime, bool $expect, string $why): void
    {
        $this->assertSame($expect, UploadController::isSupportedMime($mime), $why);
    }

    public static function mimeCases(): array
    {
        return [
            ['image/jpeg', true,  'JPG 一直是主力格式'],
            ['IMAGE/JPEG', true,  '大小写不敏感：finfo 偶尔回全大写'],
            [' image/png ', true, '带空白也要认，别把正常 PNG 挡在门外'],
            ['image/webp', true,  'WEBP 在列'],
            ['image/gif',  true,  'GIF 在列（动图原样透传，前端不会转掉它）'],
            ['image/heic', false, 'HEIC 后端不收，由前端转 JPEG；这句是前端的活'],
            ['image/svg+xml', false, 'SVG 能夹脚本，永远不许进图片上传口'],
            ['image/bmp',  false, 'BMP 不在四格式内：前端会转成 JPEG 再传'],
            ['',           false, '嗅探失败不能当成放行'],
            ['application/x-php', false, '伪装成图片的脚本必须在这里就断掉'],
        ];
    }

    public function test_五种iPhone格式单独认得出来(): void
    {
        foreach (['image/heic', 'image/heif', 'image/heics', 'image/heifs'] as $mime) {
            $this->assertTrue(UploadController::isHeic($mime), "{$mime} 应当归到 HEIC 家族，才有专属指引");
        }
        $this->assertFalse(UploadController::isHeic('image/jpeg'), '普通 JPG 不该收到 HEIC 那句话');
        $this->assertFalse(UploadController::isHeic(''), '空类型不该被说成 iPhone 格式');
    }

    // ------------------------------------------------ 5MB（没动）

    public function test_体积上限仍然是5MB(): void
    {
        $this->assertSame(5 * 1024 * 1024, self::constOf('MAX_SIZE'), '5MB 口径你说过不擅自放宽');
    }

    public function test_体积超限的话术仍然写着5MB(): void
    {
        $this->assertStringContainsString('图片大小不能超过 5MB', self::source());
    }

    // ------------------------------------------------ 拒绝话术要能照着做

    public function test_HEIC的拒绝话术给了能做完的路(): void
    {
        $msg = UploadController::unsupportedImageMessage('image/heic', 3 * 1048576);

        $this->assertStringContainsString('HEIC', $msg, '得说清这是 iPhone 的格式，不然运营以为图坏了');
        $this->assertStringContainsString('image/heic', $msg, '带上识别到的真实类型，报障时一眼对得上');
        $this->assertStringContainsString('3.0MB', $msg, '带上体积，才能分清是没下载完还是超大图');
        $this->assertStringContainsString('截图', $msg, '立刻能做的方案');
        $this->assertStringContainsString('兼容性最好', $msg, '一劳永逸的方案（iPhone 设置项）');
        $this->assertStringNotContainsString('仅支持 JPG', $msg, 'HEIC 不该只收到通用那一句');
    }

    public function test_其他不支持类型仍然报四格式(): void
    {
        $msg = UploadController::unsupportedImageMessage('image/bmp', 700 * 1024);

        $this->assertStringContainsString('仅支持 JPG / PNG / WEBP / GIF', $msg);
        $this->assertStringContainsString('image/bmp', $msg);
        $this->assertStringContainsString('700KB', $msg, '不到 1MB 用 KB，别说成 0.7MB 让人以为很小无所谓');
        $this->assertStringNotContainsString('HEIC', $msg, '跟 iPhone 无关的格式别甩手机设置那套');
    }

    public function test_类型嗅不出来时不报空(): void
    {
        $msg = UploadController::unsupportedImageMessage('', 0);

        $this->assertStringContainsString('未知类型', $msg, '不能出现「识别为 ）」这种话');
        $this->assertStringNotContainsString('，0', $msg, '体积读不出来就别写体积');
    }

    public function test_读不出图片内容的话术点名iCloud占位文件(): void
    {
        $msg = UploadController::unreadableImageMessage('image/jpeg', 20 * 1024);

        $this->assertStringContainsString('内容不完整', $msg, '原因说人话，别只说「不是有效图片」');
        $this->assertStringContainsString('image/jpeg', $msg, '类型是合格的说不出问题，更要写明是内容问题');
        $this->assertStringContainsString('重新选', $msg, '第一步：重试');
        $this->assertStringContainsString('iCloud', $msg, '第二步：iPhone 相册那朵云还没下载下来的占位文件');
        $this->assertStringContainsString('截图', $msg, '第三步：绕开原文件的兜底做法');
        $this->assertStringContainsString('20KB', $msg);
    }

    public function test_体积口语化的边界(): void
    {
        $ref = new ReflectionClass(UploadController::class);
        $size = $ref->getMethod('sizeText');
        $size->setAccessible(true);

        $this->assertSame('', $size->invoke(null, 0), '读不到体积就整段不写');
        $this->assertSame('，不足1KB', $size->invoke(null, 20), '20 字节别说成「0KB」，那听着像空文件');
        $this->assertSame('，1KB', $size->invoke(null, 1024));
        $this->assertSame('，1.0MB', $size->invoke(null, 1048576), '正好 1MB 起用 MB 说法');
        $this->assertSame('，5.0MB', $size->invoke(null, 5 * 1048576));
    }

    // ------------------------------------------------ 防漂移：那条误伤不许回来

    /** 注释里留着「当年那句拒绝话术」的原文（说明为什么删），断言时要挑掉注释行，别跟自己写的注释较真 */
    private static function codeOnly(): string
    {
        $keep = [];
        foreach (explode("\n", self::source()) as $line) {
            $t = ltrim($line);
            if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
            $keep[] = $line;
        }
        return implode("\n", $keep);
    }

    public function test_不再比对客户端文件名后缀(): void
    {
        $code = self::codeOnly();

        $this->assertStringNotContainsString('getOriginalExtension', $code,
            '客户端后缀比对已删除：它拦不到攻击（落盘名由服务端生成），只误伤了正常图片');
        $this->assertStringNotContainsString('扩展名与实际内容', $code, '那句拒绝话术不该再出现在代码里');
    }

    public function test_真内容校验仍然保留且拦在落盘之前(): void
    {
        $src = self::source();

        $this->assertStringContainsString('getimagesize', $src, '放宽的只是后缀，polyglot 内容检查必须留着');
        $this->assertStringContainsString('@getimagesize', $src, '要 @ 抑制：读不出时 PHP 会顺带打 warning');
        $this->assertLessThan(
            strpos($src, '$file->move('),
            strpos($src, 'getimagesize'),
            '内容检查必须在 move() 之前：不能先落盘再验'
        );
        $this->assertStringContainsString("bin2hex(random_bytes(16)) . '.' . \$ext", $src,
            '文件名继续由服务端按嗅探类型生成，这是「后缀比对没用」的依据');
    }

    public function test_白名单类型与话术里的格式列表一致(): void
    {
        $src = self::source();
        $this->assertStringContainsString('仅支持 JPG / PNG / WEBP / GIF', $src,
            '四格式写死在白名单里，文案少一种就是漂移');
    }

    // ------------------------------------------------ 跨端：前后端同一句、同一个数

    public function test_后台前端的总闸与后端同口径(): void
    {
        $clientFile = dirname(__DIR__, 3) . '/sinan-admin/src/api/index.js';
        if (!is_file($clientFile)) {
            $this->markTestSkipped('只在本机 monorepo（两个仓库同级）才锁得住跨端口径');
        }
        $src = (string) file_get_contents($clientFile);

        $this->assertStringContainsString('const MAX_UPLOAD_IMAGE_BYTES = 5 * 1024 * 1024', $src,
            '前端总闸与后端 MAX_SIZE 必须同一个数，否则前端放行的图后端会回 4220');
        $this->assertStringContainsString('图片大小不能超过 5MB', $src, '同一句话，运营在两处看到的是同一个说法');

        // 前端「原样透传」的格式集合 = 后端白名单，谁多谁少都会造成「前端觉得能传、后端拒收」
        preg_match("/UPLOAD_NATIVE_MIMES = \[([^\]]*)\]/", $src, $m);
        $this->assertNotEmpty($m[1] ?? '', '前端得有一份原样透传的类型清单');
        $clientTypes = array_values(array_filter(array_map(
            static fn($s) => trim(trim($s), "'"),
            explode(',', $m[1])
        )));
        // 前端多认一个 image/jpg（浏览器不产这个类型，历史上手写过），后端用 image/jpeg：除去这个别名必须完全对齐
        $clientTypes = array_values(array_diff($clientTypes, ['image/jpg']));
        $this->assertSame(array_keys(self::constOf('ALLOWED')), $clientTypes,
            '前端透传的类型必须与后端白名单一一对应（顺序也按后端来）');
    }
}

<?php
declare(strict_types=1);

namespace tests\unit;

use app\admin\controller\UploadController;
use app\admin\service\UploadCleanupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 后台「图片清理」的保护闸门（UploadCleanupService::protectedReason）
 *
 * 起因：2026-10-12 只读体检（RUNBOOK 7.20 S3）发现两处会把**在用的图**判成「零引用」：
 *   1. 服务顶部注释写着「announcement 刻意不扫」，但这条只存在于注释里 ——
 *      scanUnreferenced() 是递归遍历整个 public/uploads，只有传了 biz 参数才过滤，
 *      而控制器 biz 默认 null、前端默认不选。同时引用扫描列里 announcements 只有 cover_image，
 *      正文 content 里的 <img> 压根没算引用 ⇒ 公告正文图必然进零引用列表、能被勾走。
 *   2. 「先传图、后点保存」这段时间里那张图在任何表里都没有引用，也没有任何时间阈值保护它。
 *
 * 修法是**一个闸门管两条**（沿用 7.10「所有传图处对齐创建藏品」那条单点规矩）：
 * protectedReason() 只有一处判定，扫描列表与移入回收站都问它，谁都不许自己写一份。
 *
 * 这里只测纯函数与源码事实：真扫描要框架与磁盘，留给 --testsuite api。
 */
class UploadCleanupProtectionTest extends TestCase
{
    private UploadCleanupService $svc;

    protected function setUp(): void
    {
        $this->svc = new UploadCleanupService();
    }

    private static function constOf(string $name)
    {
        $ref = new ReflectionClass(UploadCleanupService::class);
        $const = $ref->getReflectionConstant($name);
        self::assertNotFalse($const, "UploadCleanupService 应当还有常量 {$name}");
        return $const->getValue();
    }

    /** 挑掉注释行：源码里的解释文字会引用被禁的词，逐行比对代码本身才准 */
    private static function codeOnly(string $src): string
    {
        $keep = [];
        foreach (explode("\n", $src) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                continue;
            }
            $keep[] = $line;
        }
        return implode("\n", $keep);
    }

    private static function serviceSource(): string
    {
        $file = dirname(__DIR__, 2) . '/app/admin/service/UploadCleanupService.php';
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    private static function controllerSource(): string
    {
        $file = dirname(__DIR__, 2) . '/app/admin/controller/UploadCleanupController.php';
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    // ------------------------------------------------ 公告目录：真挡掉了

    public function test_公告目录不参与批量清理(): void
    {
        $this->assertNotContains(
            'announcement',
            $this->svc->bizDirs(),
            'announcement 一旦进清理名单，正文里的 <img> 就可能被当垃圾移走（历史引用我们扫不全）'
        );
        $this->assertSame(
            ['announcement'],
            $this->svc->excludedBizDirs(),
            '刻意排除的应当只有公告这一项，多一个就说明两边名单漂了'
        );
    }

    public function test_排除名单是从上传白名单算出来的而不是另抄一份(): void
    {
        // 上传接口新加一种 biz 时，清理侧不必跟着改；两边名单一相减，排除项自动就是差集
        $expect = array_values(array_diff(UploadController::BIZ_DIRS, $this->svc->bizDirs()));
        $this->assertEqualsCanonicalizing($expect, $this->svc->excludedBizDirs());
    }

    #[DataProvider('bizCases')]
    public function test_闸门按目录名放行或拦下(string $biz, bool $shouldProtect): void
    {
        $old = time() - 30 * 86400; // 一个月前传的，宽限期早过了，只剩目录这一条
        $reason = $this->svc->protectedReason($biz, $old, time());
        if ($shouldProtect) {
            $this->assertNotNull($reason, "「{$biz}」不在清理名单里，必须给出跳过原因");
            $this->assertStringContainsString('不参与自动清理', (string) $reason);
        } else {
            $this->assertNull($reason, "「{$biz}」是正常业务目录，过了宽限期就该能被清理");
        }
    }

    public static function bizCases(): array
    {
        return [
            ['announcement', true],
            ['collection',   false],
            ['content',      false],
            ['misc',         false],
            ['evil',         true],
        ];
    }

    // ------------------------------------------------ 新上传的宽限期

    public function test_宽限期小时数由服务单点给出(): void
    {
        $this->assertSame(24, $this->svc->graceHours(), '接口和前端提示语都读这个数，别再写死第二处');
    }

    #[DataProvider('ageCases')]
    public function test_刚上传的图在宽限期内不许动(int $hoursAgo, bool $shouldProtect, string $why): void
    {
        $now = 1_800_000_000;
        $reason = $this->svc->protectedReason('collection', $now - $hoursAgo * 3600, $now);
        if ($shouldProtect) {
            $this->assertNotNull($reason, $why);
            $this->assertStringContainsString('上传不足', (string) $reason, '话术要让运营看懂「还没保存」这件事');
            $this->assertStringContainsString('小时', (string) $reason);
        } else {
            $this->assertNull($reason, $why);
        }
    }

    public static function ageCases(): array
    {
        return [
            [0,   true,  '刚传完就点清理：还没保存，必须保护'],
            [1,   true,  '编辑到一半'],
            [23,  true,  '差一小时到期，仍属宽限期'],
            [24,  false, '整 24 小时视为已过宽限期（判定用 <，边界要能清）'],
            [48,  false, '两天前的图没人引用才是真孤儿'],
            [720, false, '一个月前的孤儿图照常可清'],
        ];
    }

    public function test_未来的时间戳不许把闸门绕成永久保护(): void
    {
        // 磁盘时钟异常 / NAS 回滚时 mtime 可能在未来：age 为负数仍算「宽限期内」，
        // 但话术里的已传小时数不能出现负数，看着像 bug
        $now = 1_800_000_000;
        $reason = $this->svc->protectedReason('collection', $now + 86400, $now);
        $this->assertNotNull($reason, '时间戳在未来时倾向于保护而不是删除');
        $this->assertStringNotContainsString('-1', (string) $reason, '话术不该冒负数');
    }

    // ------------------------------------------------ 引用扫描列：正文算引用

    public function test_公告正文列在引用扫描范围内(): void
    {
        $columns = self::constOf('IMAGE_COLUMNS');
        $this->assertArrayHasKey('announcements', $columns);
        $this->assertContains(
            'content',
            $columns['announcements'],
            '正文里的 <img> 也是引用；只扫 cover_image 会把正在用的正文图判成零引用（7.20 S3 第 1 条）'
        );
        $this->assertContains('cover_image', $columns['announcements'], '封面列别在改的时候顺手删掉');
    }

    public function test_正文里的上传图能被抽成引用(): void
    {
        $html = '<p>看这张</p><img src="/uploads/announcement/202610/abc123.png" alt="">';
        $urls = $this->svc->extractUploadUrls($html);
        $this->assertContains('/uploads/announcement/202610/abc123.png', $urls, '正则扫整段值，富文本内嵌 URL 要能提出来');
    }

    // ------------------------------------------------ 闸门真的被两边用上（防漂移）

    public function test_扫描与回收两道都问同一个闸门(): void
    {
        $src = self::codeOnly(self::serviceSource());

        $this->assertMatchesRegularExpression('/scanUnreferenced\([^)]*\)/', $src);
        $this->assertStringContainsString('$this->protectedReason(', $src);
        $this->assertSame(
            2,
            preg_match_all('/\$this->protectedReason\(/', $src),
            '闸门应当恰好被 scanUnreferenced 与 trashFiles 各调一次；多一处就说明有人又开始各写一套'
        );
    }

    public function test_闸门不许把自己写成黑名单(): void
    {
        $src = self::codeOnly(self::serviceSource());

        $this->assertMatchesRegularExpression(
            '/in_array\(\$biz,\s*self::BIZ_DIRS,\s*true\)/',
            $src,
            '按「白名单」判定：新出现的目录默认不参与清理，别改成「只拦 announcement」那种一漏就炸的写法'
        );
    }

    public function test_移入回收站不许跳过复核(): void
    {
        $src = self::codeOnly(self::serviceSource());

        $trashBody = self::methodBody($src, 'trashFiles');
        $this->assertStringContainsString('collectReferencedUrls', $trashBody, '删除前必须重新收一遍引用：列表可能已经过期');
        $this->assertStringContainsString('protectedReason', $trashBody, '列表可能是在宽限期之前打开的，动手时要再判一次');
        $this->assertStringContainsString('is_file(', $trashBody, '文件不存在的要归 missing，不能当成移动成功');
    }

    public function test_接口要把受保护的数量与口径带给前端(): void
    {
        $src = self::codeOnly(self::controllerSource());

        $this->assertStringContainsString('protected', $src, '零引用列表要如实告诉运营「这里少了几张、为什么少」');
        $this->assertStringContainsString('graceHours', $src, '宽限期小时数由服务下发，前端不许自己写死');
        $this->assertStringContainsString('excludedBizDirs', $src, '不参与清理的目录名单同上');
        $this->assertStringContainsString('受保护', $src, '移入回收站的结果话术要包含被保护跳过的条数');
    }

    /** 粗略截出一个方法体：够用来查「这个方法里有没有某句」，不做语法解析 */
    private static function methodBody(string $src, string $name): string
    {
        $at = strpos($src, 'function ' . $name);
        self::assertNotFalse($at, "源码里应当还有 {$name}()");
        $rest = substr($src, $at);
        $end = strpos($rest, "\n    }");
        return $end === false ? $rest : substr($rest, 0, $end);
    }

    // ------------------------------------------------ 跨端锁：前端不许抄一份数字

    public function test_后台页面不许自己写死宽限期与目录名单(): void
    {
        $file = dirname(__DIR__, 3) . '/sinan-admin/src/views/system/ImageCleanup.vue';
        if (!is_file($file)) {
            $this->markTestSkipped('没有 sinan-admin 仓库，跳过跨端锁');
        }
        $src = (string) file_get_contents($file);

        $this->assertStringContainsString('graceHours', $src, '提示语里的「多少小时」要读接口，别写死');
        $this->assertStringContainsString('excludedBiz', $src, '「哪些目录不参与清理」同样要读接口');
        $this->assertStringContainsString('protected', $src, '受保护张数要显示出来，否则列表空着会被当成「真的没垃圾」');
    }
}

<?php
declare(strict_types=1);

namespace tests\unit;

use app\controller\BlindBoxes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 盲盒「奖池展示口径」（BlindBoxes::poolOf 的两个纯函数）
 *
 * 背景：C 端详情页的「盲盒内容」模板早就写好了，但后端从没下发奖池、
 * 前端读的字段也不存在，导致这块从来没显示过一次。接上时把两件
 * 「没有列、只能靠约定」的事钉死在单点里：
 *   1. 概率列存的是 (0,1] 的小数（后台校验见 admin BlindBoxController::create），
 *      C 端要的是「100%」这种文案，不能把 1 原样发出去显示成「1」；
 *   2. 稀有度数据库里根本没有列，只能由概率推。
 * 这两件事只要有第二处各写一遍，同一件奖品就会在两个页面显示成两个标签。
 */
class BlindBoxPoolDisplayTest extends TestCase
{
    // ------------------------------------------------------------ 概率文案

    #[DataProvider('percentCases')]
    public function test_概率小数换成百分数文案(float $p, string $expect, string $why): void
    {
        $this->assertSame($expect, BlindBoxes::percentText($p), $why);
    }

    public static function percentCases(): array
    {
        return [
            [1.0,     '100%',   '必得：后台填 1，C 端不能显示成 1 或 100.00%'],
            [0.5,     '50%',    '整档去掉尾零'],
            [0.1,     '10%',    '后台填 0.1'],
            [0.0375,  '3.75%',  '两位小数保留有效位'],
            [0.0001,  '0.01%',  '最小可表示档，不能塌成 0%'],
            [0.0,     '0%',     '脏数据兜底：显示 0% 而不是 .%'],
            [0.3333,  '33.33%', '四舍五入到两位'],
            [0.33335, '33.34%', '进位边界（decimal(5,4) 存不进第五位，纯函数仍要正确）'],
        ];
    }

    // ------------------------------------------------------------ 稀有度

    #[DataProvider('rarityCases')]
    public function test_稀有度按概率分四档(float $p, string $expect, string $why): void
    {
        $this->assertSame($expect, BlindBoxes::rarityOf($p), $why);
    }

    public static function rarityCases(): array
    {
        return [
            [1.0,    '普通', '必得的东西不配叫稀有'],
            [0.5,    '普通', '档边界含等号'],
            [0.4999, '稀有', '刚掉出普通档'],
            [0.2,    '稀有', '档边界含等号'],
            [0.1999, '史诗', '刚掉出稀有档'],
            [0.05,   '史诗', '档边界含等号'],
            [0.0499, '传说', '刚掉出史诗档'],
            [0.0001, '传说', '最小概率'],
        ];
    }

    /** 概率越低标签必须越稀有：四档顺序一旦写反，用户看到的就是「1% 是普通、50% 是传说」 */
    public function test_概率与稀有度单调反向(): void
    {
        $order = ['普通', '稀有', '史诗', '传说'];
        // 用列表而不是「概率 => 档位」的映射：PHP 会把 float 键截断成 int，
        // 0.3 / 0.1 / 0.01 会全塌到同一个键 0，测试就只比对了最后一次写入。
        $samples = [1.0, 0.3, 0.1, 0.01];
        $ranks = [];
        foreach ($samples as $p) {
            $label = BlindBoxes::rarityOf($p);
            $rank  = array_search($label, $order, true);
            $this->assertNotFalse($rank, '稀有度只能取四档之一：' . $label);
            $ranks[] = $rank;
        }
        $this->assertSame(0, $ranks[0], '概率 1（必得）必须是最低档');
        for ($i = 1; $i < count($ranks); $i++) {
            $this->assertGreaterThan($ranks[$i - 1], $ranks[$i],
                '概率从 ' . $samples[$i - 1] . ' 降到 ' . $samples[$i] . ' 时稀有度档位必须递增');
        }
    }

    /** 四档文案与 H5 的角标样式同名（CollectionDetail.vue 的 &--普通/稀有/史诗/传说），改名要两边一起改 */
    public function test_稀有度文案只允许那四种(): void
    {
        $allow = ['普通', '稀有', '史诗', '传说'];
        foreach ([1.0, 0.5, 0.4, 0.2, 0.1, 0.05, 0.01, 0.0001] as $p) {
            $this->assertContains(BlindBoxes::rarityOf($p), $allow);
        }
    }

    // -------------------------------------------------- 防漂移：只有一条路

    /** app/ 下所有 PHP 文件 */
    private static function appFiles(): \Generator
    {
        $root = dirname(__DIR__, 2) . '/app';
        $it   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') yield $f;
        }
    }

    /**
     * C 端任何一处要展示奖池，都必须调 BlindBoxes::poolOf；
     * 自己 join blind_box_items 出「给 C 端看的」列表 = 第二份口径，迟早漂移。
     * 后台（app/admin）与开盒抽奖（BlindBoxes::open）不在此列：
     * 前者是配置视图、后者要行锁和剩余限量，语义都不是「展示」。
     */
    public function test_C端奖池展示只能经poolOf单点(): void
    {
        $hit = [];
        foreach (self::appFiles() as $f) {
            if (!str_contains(str_replace('\\', '/', $f->getPathname()), '/app/controller/')) continue;
            if (str_ends_with($f->getPathname(), 'BlindBoxes.php')) continue;
            $src = (string) file_get_contents($f->getPathname());
            if (str_contains($src, "blind_box_items")) {
                $hit[] = $f->getFilename();
            }
        }
        $this->assertSame([], $hit, 'C 端这些文件绕过 poolOf 自己查奖池：' . implode('、', $hit));
    }

    /** 详情接口必须真的把奖池下发出去，否则前端那块模板又变回死代码 */
    public function test_详情接口下发奖池字段(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/controller/Collections.php');
        $this->assertMatchesRegularExpression(
            "/'items'\s*=>\s*\\\$isBlindBox\s*\?\s*BlindBoxes::poolOf\(/",
            $src,
            '藏品详情必须用 BlindBoxes::poolOf 下发 items（C 端「盲盒内容」的唯一数据源）'
        );
    }

    /** 概率分母是 1 而不是 100：后台按 (0,1] 校验，展示层若按百分数再乘一次就变 10000% */
    public function test_后台概率区间口径未变(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/admin/controller/BlindBoxController.php');
        $this->assertStringContainsString('$prob > 1', $src, '后台仍按 (0,1] 校验概率，展示层才能乘 100');
    }
}

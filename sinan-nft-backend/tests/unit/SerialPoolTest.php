<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\SerialException;
use app\service\SerialService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 藏品编号号池（SerialService 的纯计算部分，不需要数据库）
 *
 * 需求方定的口径（2026-10-11）：
 *   「藏品发行 1000 份，编号最大不能超过 1000。且发放取随机数，编号显示 #1000 这样。」
 * 拆成可断言的四条：
 *   A. 越界即 bug：任何一次取号都必须落在 1..发行量 内；
 *   B. 随机而非排号：不能按发放先后排 1、2、3……（否则第 11 个买家拿到 #11，
 *      而前 10 个号已被空投占掉，直接撞唯一索引）；
 *   C. 发完就报错：宁可抛「编号已发完」，也不能悄悄发第 1001 号；
 *   D. 已消耗的号不回收，同一个号永远只属于一个人。
 *
 * 这里测的是 pickFromPool/pickWithoutEdition/format/parseNo 这些纯函数；
 * allocate() 需要先锁 collectibles 行，归到需要 MySQL 的 api 套件里跑。
 * 私有方法用反射调：它们是号池规则的唯一实现，锁不住就等于规则没锁。
 */
class SerialPoolTest extends TestCase
{
    /** 反射调用 SerialService 的私有静态方法（PHP 8.1 起私有方法可直接 invoke，无需 setAccessible） */
    private static function call(string $method, array $args): array
    {
        return (new ReflectionMethod(SerialService::class, $method))->invokeArgs(null, $args);
    }

    /** 已占用号集合：与 usedNumbers() 的返回结构一致（号 => true） */
    private static function used(array $numbers): array
    {
        return array_fill_keys($numbers, true);
    }

    // ---------------------------------------------------------------- 编号串

    public function test_编号串补零到四位且不截断(): void
    {
        $this->assertSame('SN-2-0007', SerialService::format(2, 7));
        $this->assertSame('SN-2-0001', SerialService::format(2, 1));
        // 四位刚好用满
        $this->assertSame('SN-2-1000', SerialService::format(2, 1000));
        // 超过四位原样输出，绝不能截断成 2345（截断=重号）
        $this->assertSame('SN-2-12345', SerialService::format(2, 12345));
    }

    #[DataProvider('parseCases')]
    public function test_从编号串反解纯序号(?string $serial, ?int $expected, string $why): void
    {
        $this->assertSame($expected, SerialService::parseNo($serial), $why);
    }

    public static function parseCases(): array
    {
        return [
            ['SN-2-0007',    7,    '标准编号补前导零'],
            ['SN-2-0000',    null, '0 不是合法编号，不能让 #0 上线'],
            ['SN-9-1000',    1000, '四位用满'],
            ['SN-12-0042',   42,   '藏品 ID 多位也能解'],
            ['SN-2-12345',   12345, '五位原样解析'],
            ['TMP-2-0001',   null, '重编脚本留下的占位符必须忽略，否则会当成已占用号'],
            ['SN-2-0007-x',  null, '畸形串不参与号池'],
            ['SN-2-',        null, '缺序号的残缺串不参与号池'],
            ['',             null, '空串'],
            [null,           null, 'NULL（历史行没写编号）'],
            ['莲塘乳鹿图',    null, '把名称写进编号列的脏数据不参与号池'],
        ];
    }

    public function test_拼号与解号互逆(): void
    {
        foreach ([1, 7, 999, 1000, 12345] as $n) {
            $this->assertSame($n, SerialService::parseNo(SerialService::format(3, $n)), "序号 {$n} 必须能原样解回");
        }
    }

    // ------------------------------------------------------------ A. 不越界

    public function test_取号永不越出一点到发行量(): void
    {
        // 发行 1000，已被前 10 个号占用（模拟"先空投 10 份再卖"）
        $picked = self::call('pickFromPool', [7, 1000, self::used(range(1, 10)), 50]);
        $this->assertCount(50, $picked, '要 50 个就必须给 50 个');
        foreach ($picked as $n) {
            $this->assertGreaterThanOrEqual(1, $n, "编号 {$n} 越下界");
            $this->assertLessThanOrEqual(1000, $n, "编号 {$n} 越过发行量 1000（超发）");
            $this->assertArrayNotHasKey($n, self::used(range(1, 10)), "编号 {$n} 发的是已占用的号");
        }
        $this->assertCount(50, array_unique($picked), '同一次批量取号内部不得重号');
    }

    public function test_全部取完时恰好是一到发行量的全集(): void
    {
        $picked = self::call('pickFromPool', [7, 10, self::used([]), 10]);
        sort($picked);
        $this->assertSame(range(1, 10), $picked, '发满 10 份就是 1..10，一个不多一个不少');
    }

    public function test_只剩最后一个号时取到的是那个号(): void
    {
        $picked = self::call('pickFromPool', [7, 10, self::used(range(1, 9)), 1]);
        $this->assertSame([10], $picked);
    }

    // -------------------------------------------------------- B. 随机而非排号

    public function test_取号是随机抽取而不是按先后排号(): void
    {
        // 每次都是空号池，等价于「第 1 件、第 2 件……发出去时拿到的号」
        $firsts = [];
        for ($i = 0; $i < 50; $i++) {
            $picked   = self::call('pickFromPool', [7, 1000, self::used([]), 1]);
            $firsts[] = $picked[0];
        }
        $distinct = count(array_unique($firsts));
        $this->assertGreaterThanOrEqual(5, $distinct, "50 次取号只出现 {$distinct} 个不同号，随机性失效");
        // 排号实现的特征：永远从最小空号开始。随机实现的批量结果不该等于 1..N
        $sequential = self::call('pickFromPool', [7, 1000, self::used([]), 5]);
        $this->assertNotSame([1, 2, 3, 4, 5], $sequential, '按 1..N 顺序排号说明随机取号退化成排号');
    }

    // -------------------------------------------------- C. 号池耗尽必须报错

    public function test_号池发完时抛错且不发越界号(): void
    {
        try {
            self::call('pickFromPool', [7, 10, self::used(range(1, 10)), 1]);
            $this->fail('号池已满却没报错，会发出 11 号（编号大于发行量）');
        } catch (SerialException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('编号已发完', $msg);
            $this->assertStringContainsString('发行量 10', $msg, '报错要带数字，便于当场判断');
            $this->assertStringContainsString('扩充发行量', $msg, '要给出下一步动作');
        }
    }

    public function test_一次要的比剩余多时报错(): void
    {
        $this->expectException(SerialException::class);
        $this->expectExceptionMessage('本次需要 6 个');
        self::call('pickFromPool', [7, 10, self::used(range(1, 5)), 6]);
    }

    public function test_越界旧号不占池但也不吞新号(): void
    {
        // 重编脚本还没跑的存量：1..5 正常，11..20 是旧的「行 id 当编号」越界号
        $used = self::used(array_merge(range(1, 5), range(11, 20)));
        $picked = self::call('pickFromPool', [7, 10, $used, 5]);
        foreach ($picked as $n) {
            $this->assertGreaterThanOrEqual(6, $n, '越界旧号不在池内，可用号只有 6..10');
            $this->assertLessThanOrEqual(10, $n);
        }
        $this->assertCount(5, array_unique($picked));

        // 第 6 个才是真的没了：池内只剩 5 个可用
        $this->expectException(SerialException::class);
        self::call('pickFromPool', [7, 10, $used, 6]);
    }

    public function test_超大发行量走随机探测分支(): void
    {
        // 阈值是 100000，超过它就不建全量差集（省内存），改成随机探测
        $picked = self::call('pickFromPool', [7, 100001, self::used([]), 5]);
        $this->assertCount(5, $picked);
        foreach ($picked as $n) {
            $this->assertGreaterThanOrEqual(1, $n);
            $this->assertLessThanOrEqual(100001, $n);
        }
        $this->assertCount(5, array_unique($picked));
    }

    public function test_超大发行量发完时立刻报错而不是空转探测(): void
    {
        // 号池 100% 占满：可用数判定在探测循环之前，必须一次到位地报错
        $this->expectException(SerialException::class);
        $this->expectExceptionMessage('编号已发完');
        self::call('pickFromPool', [7, 100001, self::used(range(1, 100001)), 1]);
    }

    // -------------------------------------------- 发行量缺失（edition<=0）兜底

    public function test_发行量缺失时用最大已用号递增且不重号(): void
    {
        // 历史脏数据里 edition=0 的藏品：不封顶，但至少同藏品内不重号
        $this->assertSame([1, 2, 3], self::call('pickWithoutEdition', [self::used([]), 3]));
        $this->assertSame([8, 9], self::call('pickWithoutEdition', [self::used([3, 7]), 2]));
    }

    // ------------------------------------------------------------ 入参校验

    public function test_藏品ID非法时拒绝取号(): void
    {
        // 这两条校验在任何数据库访问之前，所以能离线跑
        $this->expectException(SerialException::class);
        $this->expectExceptionMessage('藏品 ID 非法');
        SerialService::allocate(0);
    }

    public function test_本次取号数量为非法值时拒绝(): void
    {
        $this->expectException(SerialException::class);
        $this->expectExceptionMessage('至少为 1');
        SerialService::allocate(1, 0);
    }

    // ---------------------------------------------------- 防漂移：只有一条路

    /** app/ 下所有 PHP 文件（跳过 SerialService 自己） */
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

    public function test_编号串只允许在SerialService里拼(): void
    {
        $hit = [];
        foreach (self::appFiles() as $f) {
            if (str_ends_with($f->getPathname(), 'SerialService.php')) continue;
            $src = (string) file_get_contents($f->getPathname());
            // 自己拼 'SN-' 前缀，或把行 id / sold 当编号，都是绕过号池
            if (preg_match("/'SN-'\s*\./", $src) || preg_match('/self::PREFIX\s*\./', $src)) {
                $hit[] = $f->getFilename();
            }
            if (preg_match("/'serial'\s*=>\s*\\\$uc[Ii]d\b/", $src) || preg_match("/'serial'\s*=>\s*sold/", $src)) {
                $hit[] = $f->getFilename() . '（用行 id 或 sold 当编号）';
            }
        }
        $this->assertSame([], $hit, '编号必须只由 SerialService 一处生成，这些地方在自己拼号：' . implode('、', $hit));
    }

    public function test_插入资产行的文件都必须经SerialService取号(): void
    {
        $files  = [];
        $missing = [];
        foreach (self::appFiles() as $f) {
            $src = (string) file_get_contents($f->getPathname());
            if (strpos($src, "user_collectibles')->insert") === false) continue;
            $files[] = $f->getFilename();
            if (strpos($src, 'SerialService::') === false) $missing[] = $f->getFilename();
        }
        // 13 处发放点（购买/空投/开盒/合成/分解/抽签/抽奖/活动奖励/后台补单/换绑……）
        $this->assertGreaterThanOrEqual(13, count($files), '扫描结果为空说明匹配串失效，这条防漂移测试就成了摆设');
        $this->assertSame([], $missing, implode('、', $missing) . ' 直接插入资产行却没走 SerialService');
    }
}

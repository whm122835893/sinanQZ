<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\RaffleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 抽签购「中签后开放购买」闸门（RaffleService 的纯判定部分，不需要数据库）
 *
 * 需求方原话：「中签五分钟后购买，中签五分钟之后就显示购买按钮，未中签不显示。」
 * 落到可断言的五条：
 *   1. 只有中签者（draw_status=1）才可能可购，未中签/未报名一律 false ——
 *      false 就是"连按钮都不给"，C 端不显示禁用态；
 *   2. 开放时刻 = 中签时刻 + 300 秒，那一刻之前只给倒计时，不给按钮；
 *   3. 中签时刻取实际开奖时间 drawn_at，缺失才退回计划抽签时间 draw_time；
 *   4. purchase_start 彻底退出判定：它过去留空会写成 0000-00-00，
 *      于是窗口永久锁死，用户报的"中签了却没有购买按钮"就是这条链路；
 *   5. 购满、过期是"有原因的不行"，等待期是"倒计时中的不行"，
 *      三者必须在同一个返回里区分开，否则前端只能显示"当前不可购买"。
 *
 * C 端显隐（/raffle/activities/:id、/raffle/my）与提交（/purchase）都读这一个 gate，
 * 因此下面这些用例同时是"按钮亮着却被后端打回"的防线。
 */
class RafflePurchaseGateTest extends TestCase
{
    /** 活动行模板：已开奖（status=3），10:00 开奖 → 10:05 开放购买 */
    private static function act(array $over = []): array
    {
        return array_merge([
            'status'         => 3,
            'drawn_at'       => '2026-10-11 10:00:00',
            'draw_time'      => null,
            'purchase_start' => null,
            'purchase_end'   => null,
            'sale_quantity'  => 1,
        ], $over);
    }

    /** 报名行模板：默认中签 1 次、还没买 */
    private static function reg(array $over = []): array
    {
        return array_merge([
            'draw_status'       => 1,
            'win_count'         => 1,
            'purchased_quantity'=> 0,
        ], $over);
    }

    // ------------------------------------------------------------ 延时与时刻

    public function test_延时口径是中签后五分钟(): void
    {
        $this->assertSame(300, RaffleService::PURCHASE_DELAY_SECONDS, '需求写死 5 分钟，改这个常量等于改口径');
    }

    #[DataProvider('timeNormalizingCases')]
    public function test_购买时间归一($value, ?string $expected, string $why): void
    {
        $this->assertSame($expected, RaffleService::normalizePurchaseTime($value), $why);
    }

    public static function timeNormalizingCases(): array
    {
        return [
            ['2026-10-11 15:04:05', '2026-10-11 15:04:05', '合法时间原样归一'],
            [' 2026-10-11 15:04:05 ', '2026-10-11 15:04:05', '首尾空格要清掉，否则 strtotime 之外的比较会错'],
            ['2026/10/11 15:04',     '2026-10-11 15:04:00', '缺秒补齐，下发给前端的格式统一'],
            ['',                     null, '后台留空落成 NULL'],
            ['   ',                  null, '纯空格也落 NULL'],
            [null,                   null, '没传'],
            ['0000-00-00 00:00:00',  null, 'MySQL 零日期：过去它让窗口永久锁死，必须归 NULL'],
            ['0000-00-00',           null, '只有日期部分的零日期'],
            ['not a date',           null, '非法串不能当成时间'],
            [0,                      null, '数字 0 不是时间'],
            ['2026-13-45 99:99:99',  null, '越界日期 strtotime 为 false，归 NULL'],
        ];
    }

    public function test_开放时刻以实际开奖时间为准(): void
    {
        // drawn_at 与 draw_time 同时存在时，用户等的是"真的开奖那一刻 + 5 分钟"
        $open = RaffleService::purchaseOpenAt(self::act([
            'drawn_at'  => '2026-10-11 10:00:00',
            'draw_time' => '2026-10-11 09:00:00',
        ]));
        $this->assertSame('2026-10-11 10:05:00', $open);
    }

    public function test_没记录实际开奖时间时退回计划抽签时间(): void
    {
        foreach ([null, '', '0000-00-00 00:00:00'] as $dirty) {
            $open = RaffleService::purchaseOpenAt(self::act([
                'drawn_at'  => $dirty,
                'draw_time' => '2026-10-11 09:00:00',
            ]));
            $this->assertSame('2026-10-11 09:05:00', $open, 'drawn_at 为 ' . var_export($dirty, true) . ' 时应退回 draw_time');
        }
    }

    public function test_两个开奖时间都缺失时不臆造开放时刻(): void
    {
        $this->assertNull(RaffleService::winMoment(self::act(['drawn_at' => null, 'draw_time' => null])));
        $this->assertNull(RaffleService::purchaseOpenAt(self::act(['drawn_at' => null, 'draw_time' => null])));
    }

    // -------------------------------------------------------- 1. 只有中签者可购

    public function test_未报名的人不可购(): void
    {
        $g = RaffleService::purchaseGate(self::act(), null, '2026-10-11 12:00:00');
        $this->assertFalse($g['registered']);
        $this->assertFalse($g['win']);
        $this->assertFalse($g['purchasable'], '没报名连抽签资格都没有，不该出现购买按钮');
        $this->assertSame(0, $g['quota']);
    }

    public function test_未中签的人不可购且不显示禁用态(): void
    {
        // draw_status: 0 未开奖 / 1 中签 / 2 未中签
        $g = RaffleService::purchaseGate(self::act(), self::reg(['draw_status' => 2]), '2026-10-11 12:00:00');
        $this->assertTrue($g['registered']);
        $this->assertFalse($g['win']);
        $this->assertFalse($g['purchasable']);
        $this->assertSame(0, $g['quota'], '未中签者没有可购额度');
    }

    public function test_报名了但还没开奖时不可购(): void
    {
        $g = RaffleService::purchaseGate(
            self::act(['status' => 2, 'drawn_at' => null, 'draw_time' => '2026-10-11 10:00:00']),
            self::reg(['draw_status' => 0]),
            '2026-10-11 10:03:00'
        );
        $this->assertFalse($g['drawn'], '活动 status=2 是抽签中，还没开奖');
        $this->assertFalse($g['purchasable']);
    }

    public function test_活动未进入已结束状态时一律不可购(): void
    {
        foreach ([0 => '草稿', 1 => '报名中', 2 => '抽签中', 4 => '已取消'] as $status => $label) {
            $g = RaffleService::purchaseGate(
                self::act(['status' => $status, 'drawn_at' => '2026-10-11 10:00:00']),
                self::reg(),
                '2026-10-11 11:00:00'
            );
            $this->assertFalse($g['purchasable'], "{$label}（status={$status}）不该能买");
        }
    }

    // --------------------------------------------------- 2. 五分钟等待期与边界

    public function test_中签后五分钟内只有倒计时没有按钮(): void
    {
        $g = RaffleService::purchaseGate(self::act(), self::reg(), '2026-10-11 10:04:00');
        $this->assertTrue($g['win']);
        $this->assertFalse($g['purchasable'], '等待期内不可购');
        $this->assertSame(60, $g['waitSeconds'], '还差 60 秒，前端据此显示倒计时');
        $this->assertSame('2026-10-11 10:05:00', $g['openAt']);
        $this->assertSame(300, $g['delaySeconds']);
        $this->assertFalse($g['soldOut'], '等待期不是"买满了"，别把原因写错');
        $this->assertFalse($g['expired'], '等待期不是"截止了"');
    }

    public function test_刚好到五分钟那一刻即开放(): void
    {
        $g = RaffleService::purchaseGate(self::act(), self::reg(), '2026-10-11 10:05:00');
        $this->assertSame(0, $g['waitSeconds']);
        $this->assertTrue($g['purchasable'], '口径是"中签五分钟之后就显示购买按钮"，含边界时刻');
    }

    public function test_五分钟之后可购(): void
    {
        $g = RaffleService::purchaseGate(self::act(), self::reg(), '2026-10-11 10:05:01');
        $this->assertTrue($g['purchasable']);
    }

    public function test_开奖时间缺失时按已开放处理而不是永久锁死(): void
    {
        // 已开奖（status=3）却没有 drawn_at/draw_time 的历史行：
        // 臆造一个开放时刻只会把购买按钮永久藏起来，所以这里按"等待期已过"处理
        $g = RaffleService::purchaseGate(
            self::act(['drawn_at' => null, 'draw_time' => null]),
            self::reg(),
            '2026-10-11 10:00:00'
        );
        $this->assertNull($g['openAt']);
        $this->assertSame(0, $g['waitSeconds']);
        $this->assertTrue($g['purchasable']);
    }

    // ------------------------------------------------- 4. purchase_start 已退出

    public function test_purchase_start不再影响可购判定(): void
    {
        // 这是用户报缺陷的根因：后台留空被写成 0000-00-00，旧实现拿它当窗口起点，
        // 于是「now > start」与「now < end」同时成立，永远判为不可购
        foreach ([null, '', '0000-00-00 00:00:00', '2026-10-01 00:00:00', '2027-12-31 23:59:59'] as $start) {
            $g = RaffleService::purchaseGate(
                self::act(['purchase_start' => $start]),
                self::reg(),
                '2026-10-11 10:06:00'
            );
            $this->assertTrue($g['purchasable'], 'purchase_start=' . var_export($start, true) . ' 不该再参与判定');
        }
    }

    public function test_保存活动时purchase_start一律落空(): void
    {
        // 用源码断言而不是跑 DB：这条字段是"写死 null"，防止哪天又有人从表单接回去
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/service/RaffleService.php');
        $this->assertMatchesRegularExpression('/\$purchaseStart\s*=\s*null;/', $src, 'purchase_start 必须继续被写死为 NULL');
        $this->assertMatchesRegularExpression('/\'purchase_start\'\s*=>\s*\$purchaseStart/', $src);
    }

    // ------------------------------------------------------ 5. 购满 / 截止

    public function test_额度等于中签次数乘每人限购(): void
    {
        $g = RaffleService::purchaseGate(
            self::act(['sale_quantity' => 2]),
            self::reg(['win_count' => 3, 'purchased_quantity' => 5]),
            '2026-10-11 10:06:00'
        );
        $this->assertSame(6, $g['quota']);
        $this->assertSame(5, $g['purchased']);
        $this->assertTrue($g['purchasable'], '还差 1 件没买满，仍可购');
    }

    public function test_没记录中签次数时至少按一次算(): void
    {
        // 历史行 win_count 可能是 0/NULL，退化成 1 次，别让中签者变成"零额度"
        $g = RaffleService::purchaseGate(self::act(), self::reg(['win_count' => 0]), '2026-10-11 10:06:00');
        $this->assertSame(1, $g['quota']);
    }

    public function test_买满后按钮保留但写明原因(): void
    {
        $g = RaffleService::purchaseGate(
            self::act(['sale_quantity' => 1]),
            self::reg(['win_count' => 2, 'purchased_quantity' => 2]),
            '2026-10-11 10:06:00'
        );
        $this->assertTrue($g['win']);
        $this->assertTrue($g['soldOut']);
        $this->assertFalse($g['purchasable']);
        $this->assertSame(2, $g['quota'], '提示语要说"已购满 2 件"，得把额度带出去');
    }

    public function test_过了购买截止不可购(): void
    {
        $g = RaffleService::purchaseGate(
            self::act(['purchase_end' => '2026-10-11 10:30:00']),
            self::reg(),
            '2026-10-11 10:31:00'
        );
        $this->assertSame('2026-10-11 10:30:00', $g['deadline']);
        $this->assertTrue($g['expired']);
        $this->assertFalse($g['purchasable']);
    }

    public function test_截止前仍可购(): void
    {
        $g = RaffleService::purchaseGate(
            self::act(['purchase_end' => '2026-10-11 10:30:00']),
            self::reg(),
            '2026-10-11 10:29:00'
        );
        $this->assertFalse($g['expired']);
        $this->assertTrue($g['purchasable']);
    }

    public function test_脏截止值一律当成不限(): void
    {
        // purchase_end 是选填：0000-00-00、非法串、早于开放时刻的值，
        // 都不能把窗口"提前关死"，否则又是一次"中签了却没按钮"
        foreach ([null, '', '0000-00-00 00:00:00', 'garbage', '2026-10-11 10:04:00', '2026-10-11 10:05:00'] as $end) {
            $g = RaffleService::purchaseGate(
                self::act(['purchase_end' => $end]),
                self::reg(),
                '2026-10-11 10:06:00'
            );
            $this->assertNull($g['deadline'], 'purchase_end=' . var_export($end, true) . ' 不该生效');
            $this->assertFalse($g['expired']);
            $this->assertTrue($g['purchasable']);
        }
    }

    // -------------------------------------------------------------- 健壮性

    public function test_活动行缺失时不报错且不可购(): void
    {
        // 我的抽签列表是 LEFT JOIN，活动被后台删掉时字段会缺，
        // 闸门不能被一行空数据弄崩（线上 500 最常见的原因之一）
        $g = RaffleService::purchaseGate([], self::reg(), '2026-10-11 10:06:00');
        $this->assertFalse($g['drawn']);
        $this->assertFalse($g['purchasable']);
        $this->assertSame(0, $g['quota']);
    }

    public function test_报名行为空字段时按零处理(): void
    {
        $g = RaffleService::purchaseGate(self::act(), ['draw_status' => 1], '2026-10-11 10:06:00');
        $this->assertTrue($g['win']);
        $this->assertSame(0, $g['purchased']);
        $this->assertFalse($g['soldOut']);
    }

    public function test_库里字符串数值同样能判(): void
    {
        // MySQL 驱动取出的是字符串，闸门必须容忍 '3' / '2' 这类值
        $g = RaffleService::purchaseGate(
            self::act(['status' => '3', 'sale_quantity' => '2', 'purchase_end' => '2026-10-11 11:00:00']),
            self::reg(['draw_status' => '1', 'win_count' => '1', 'purchased_quantity' => '1']),
            '2026-10-11 10:06:00'
        );
        $this->assertTrue($g['drawn']);
        $this->assertSame(2, $g['quota']);
        $this->assertSame(1, $g['purchased']);
        $this->assertTrue($g['purchasable']);
    }

    // -------------------------------------------------- 防漂移：只有一条闸门

    public function test_购买判定只出自purchaseGate一处(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        $files = ['controller/Raffle.php', 'service/RaffleService.php', 'admin/controller/MarketingController.php'];
        $hits  = [];
        foreach ($files as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) continue;
            $src = (string) file_get_contents($path);
            $n   = preg_match_all('/purchaseGate\s*\(/', $src);
            if ($n > 0) $hits[$rel] = $n;
        }
        // 三处调用：详情、我的抽签列表、下单接口
        $this->assertArrayHasKey('controller/Raffle.php', $hits, 'Raffle 控制器没调 purchaseGate，说明它自己另写了一套判定');
        $this->assertGreaterThanOrEqual(3, $hits['controller/Raffle.php'], '控制器里应有详情/列表/下单三处共用同一个 gate');

        // 其它文件不许自己拼"中签 + 5 分钟"或重新读 purchase_start 当窗口
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $bad = [];
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            if (str_ends_with($f->getPathname(), 'RaffleService.php')) continue;
            $src = (string) file_get_contents($f->getPathname());
            foreach (explode("\n", $src) as $line) {
                if (str_starts_with(trim($line), '//') || str_starts_with(trim($line), '*')) continue;
                if (preg_match('/purchase_start\s*(<=|>=|<|>)/', $line) || preg_match('/strtotime\([^)]*purchase_start/i', $line)) {
                    $bad[] = $f->getFilename() . ': ' . trim($line);
                }
            }
            if ($f->getBasename() === 'Raffle.php' && preg_match('/function\s+(inWindow|purchasable)\s*\(/', $src)) {
                // 这两个私有方法原本就是 purchaseGate 的重复实现，已删；
                // 只盯 Raffle.php，别误伤 ActivityRewardService 那个通用活动窗口 inWindow
                $bad[] = $f->getFilename() . ' 重新实现了窗口判定函数';
            }
        }
        $this->assertSame([], $bad, 'purchase_start 已退出判定，任何再拿它算窗口的代码都会重现"中签却没按钮"：' . implode(' / ', $bad));
    }
}

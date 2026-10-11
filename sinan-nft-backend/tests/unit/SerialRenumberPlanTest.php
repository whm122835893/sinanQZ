<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\SerialService;
use PHPUnit\Framework\TestCase;

/**
 * 存量编号重编：方案计算（SerialService::analyzeStock / renumberAssignments）+ 命令接线
 *
 * 背景（需求方 2026-10-11 的口径）：
 *   「藏品发行 1000 份，编号最大不能超过 1000。且发放取随机数，编号显示 #1000 这样」
 *   「存量 21 件全部按新规则重编」
 * 取号闸门（#63）只管住了新发出的号，历史遗留的两种不合规号还得靠 php think serial:renumber 清：
 *   ① 按资产行自增 ID / sold+1 生成的号，早就跑出发行量之外；
 *   ② 退役前的 TMP- 占位串，压根不是编号。
 *
 * 这里锁四件事：
 *   A. 判定口径：越界 / 占位符 / 空号 / 重号都要被抓出来，全合规的藏品不许被无故打扰；
 *   B. 拦_stop：发行量没配或存量 > 发行量时，只能报错让人先去补发行量，绝不能硬塞一个越界号；
 *   C. 方案合法性：新号全在 1..发行量 内、互不重复、覆盖且只覆盖该藏品的存量行、看起来是随机集合；
 *   D. 命令接线：方案只能出自 SerialService（一个闸门），预览分支一行都不写库，
 *      落库必须走「先挪 TMP-R-{id} 再落最终号」两段式，并同步三处按编号值存的快照。
 */
class SerialRenumberPlanTest extends TestCase
{
    /** [资产行 id => 编号串] => analyzeStock/renumberAssignments 需要的行结构 */
    private static function rows(array $pairs): array
    {
        $rows = [];
        foreach ($pairs as $id => $serial) {
            $rows[] = ['id' => (int) $id, 'serial' => $serial];
        }
        return $rows;
    }

    /** 新号里的纯序号集合（升序），便于断言「在发行量内 / 不重号」 */
    private static function numbers(array $assign): array
    {
        $out = [];
        foreach ($assign as $serial) {
            $out[] = SerialService::parseNo($serial);
        }
        sort($out);
        return $out;
    }

    private static function commandSource(): string
    {
        $file = dirname(__DIR__, 2) . '/app/command/SerialRenumber.php';
        $src  = file_get_contents($file);
        if ($src === false) {
            self::fail('读不到重编命令源码：' . $file);
        }
        return $src;
    }

    // ---------------------------------------------------------------- A. 判定口径

    public function test_编号全合规的藏品判定为无需重编(): void
    {
        $stat = SerialService::analyzeStock(1, 1000, self::rows([11 => 'SN-1-0007', 12 => 'SN-1-0342']));

        $this->assertTrue($stat['ok'], '合规存量不该被拉去重编');
        $this->assertNull($stat['blocked']);
        $this->assertSame([], $stat['issues']);
        $this->assertSame(2, $stat['count']);
    }

    public function test_编号超出发行量即判不合规(): void
    {
        // 真实缺陷形态：藏品 2 发行 10 份，历史编号却按资产行自增 ID 生成（0011、0014）
        $stat = SerialService::analyzeStock(2, 10, self::rows([21 => 'SN-2-0011', 22 => 'SN-2-0014', 23 => 'SN-2-0101']));

        $this->assertFalse($stat['ok']);
        $this->assertArrayHasKey(21, $stat['issues'], '编号 11 已在发行量之外');
        $this->assertArrayHasKey(23, $stat['issues']);
        $this->assertStringContainsString('超出发行量 10', $stat['issues'][23]);
        $this->assertStringContainsString('编号 11 超出发行量 10', $stat['issues'][21]);
    }

    public function test_发行量内的连续小号属于合规(): void
    {
        // sold+1 那种「1、2、3 排号」只要没越界、没跟资产行 ID 撞上就不算违规：
        // 重编只为修「越界/占位/重号/行ID当号」，不把用户已经见过的合规编号无故换掉
        // （要连合规行一起打乱请用 --all）
        $stat = SerialService::analyzeStock(12, 1000, self::rows([121 => 'SN-12-0001', 122 => 'SN-12-0002']));

        $this->assertTrue($stat['ok']);
        $this->assertSame([], $stat['issues']);
    }

    public function test_编号尾段等于资产行ID判不合规生产形态(): void
    {
        // 生产 2026-10-11 只读取证的真实数据：藏品 2「青色」发行 100 份，
        // 4 件存量的编号 11~14 正好等于资产行 ID 11~14 —— 旧实现直接拿行 ID 拼号。
        // 号还没越界（100 以内），但行 ID 只涨不落，迟早越过发行量，且它不是随机取的号。
        $rows = self::rows([11 => 'SN-2-0011', 12 => 'SN-2-0012', 13 => 'SN-2-0013', 14 => 'SN-2-0014']);
        $stat = SerialService::analyzeStock(2, 100, $rows);

        $this->assertFalse($stat['ok'], '行 ID 当号是旧实现的指纹，必须被抓出来');
        $this->assertCount(4, $stat['issues']);
        $this->assertStringContainsString('等于资产行 ID', $stat['issues'][11]);
        $this->assertSame([11, 12, 13, 14], array_keys($stat['issues']));
    }

    public function test_号池只有一个选择时不强行判违规(): void
    {
        // edition=1、行 ID=1：换不出别的号，判它违规就等于让这个藏品永远重编不完
        $stat = SerialService::analyzeStock(1, 1, self::rows([1 => 'SN-1-0001']));

        $this->assertTrue($stat['ok']);
    }

    public function test_占位串与空编号即判不合规(): void
    {
        $stat = SerialService::analyzeStock(3, 50, self::rows([
            31 => 'TMP-1a2b3c4d5e6f7a8b',
            32 => '',
            33 => null,
            34 => 'SN-3-0001',
        ]));

        $this->assertFalse($stat['ok']);
        $this->assertStringContainsString('无法解析', $stat['issues'][31]);
        $this->assertStringContainsString('编号为空', $stat['issues'][32]);
        $this->assertStringContainsString('编号为空', $stat['issues'][33], 'NULL 编号按空处理');
        $this->assertArrayNotHasKey(34, $stat['issues'], '合规行不该被牵连（重编范围是整个藏品，但问题清单只列违规行）');
    }

    public function test_同藏品重号时两行都记问题(): void
    {
        $stat = SerialService::analyzeStock(4, 100, self::rows([41 => 'SN-4-0009', 42 => 'SN-4-0009', 43 => 'SN-4-0010']));

        $this->assertFalse($stat['ok']);
        $this->assertStringContainsString('重复', $stat['issues'][41]);
        $this->assertStringContainsString('重复', $stat['issues'][42]);
        $this->assertArrayNotHasKey(43, $stat['issues']);
    }

    public function test_没有存量行的藏品无需重编(): void
    {
        $stat = SerialService::analyzeStock(5, 1000, []);

        $this->assertTrue($stat['ok']);
        $this->assertSame(0, $stat['count']);
        $this->assertSame([], SerialService::renumberAssignments(5, 1000, []), '空存量不该发号');
    }

    // ---------------------------------------------------------------- B. 必须先拦住的两种情况

    public function test_未配置发行量的藏品整体拦下(): void
    {
        foreach ([0, -1] as $edition) {
            $stat = SerialService::analyzeStock(6, $edition, self::rows([61 => 'SN-6-0001']));

            $this->assertFalse($stat['ok'], "edition={$edition} 时不该放行");
            $this->assertStringContainsString('未配置发行量', (string) $stat['blocked']);
            $this->assertSame([], $stat['issues'], '上限都不知道，逐行判定没有意义');
        }
    }

    public function test_存量多于发行量时先扩发行量再重编(): void
    {
        $rows = self::rows([71 => 'SN-7-0001', 72 => 'SN-7-0002', 73 => 'SN-7-0003']);
        $stat = SerialService::analyzeStock(7, 2, $rows);

        $this->assertFalse($stat['ok']);
        $this->assertStringContainsString('存量 3 件已超出发行量 2 份', (string) $stat['blocked']);
        $this->assertStringContainsString('扩充发行量', (string) $stat['blocked'], '只能先扩发行量，不能硬塞一个越界号');
    }

    // ---------------------------------------------------------------- C. 方案合法性

    public function test_新号全部落在发行量内且互不重复(): void
    {
        $rows   = self::rows([81 => 'SN-8-0081', 82 => 'SN-8-0082', 83 => 'TMP-abcd', 84 => 'SN-8-0500']);
        $assign = SerialService::renumberAssignments(8, 100, $rows);

        $this->assertSame([81, 82, 83, 84], array_keys($assign), '方案要覆盖且只覆盖这些存量行');
        $numbers = self::numbers($assign);
        $this->assertCount(4, array_unique($numbers), '同藏品不得重号');
        foreach ($numbers as $n) {
            $this->assertGreaterThanOrEqual(1, $n);
            $this->assertLessThanOrEqual(100, $n, '编号最大不能超过发行量');
        }
        foreach ($assign as $id => $serial) {
            $this->assertStringStartsWith('SN-8-', $serial);
        }
    }

    public function test_存量正好占满发行量时给出全集(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['id' => 90 + $i, 'serial' => 'SN-9-' . str_pad((string) (900 + $i), 4, '0', STR_PAD_LEFT)];
        }
        $assign = SerialService::renumberAssignments(9, 10, $rows);

        $this->assertSame(range(1, 10), self::numbers($assign), '满载时号池就是全集，不该漏号也不该报错');
    }

    public function test_重编给的是随机集合而不是从头排号(): void
    {
        $rows = self::rows([
            101 => 'SN-10-0101', 102 => 'SN-10-0102', 103 => 'SN-10-0103',
            104 => 'SN-10-0104', 105 => 'SN-10-0105',
        ]);

        $sets = [];
        for ($i = 0; $i < 50; $i++) {
            $numbers  = self::numbers(SerialService::renumberAssignments(10, 1000, $rows));
            $sets[implode(',', $numbers)] = true;
        }

        $this->assertGreaterThanOrEqual(5, count($sets), '同一批存量行连续重编 50 次，号段应当每次都不同');
        $this->assertNotSame([1, 2, 3, 4, 5], self::numbers(SerialService::renumberAssignments(10, 1000, $rows)), '不许退化成 1..n 排号');
    }

    public function test_旧号是否已合规不影响新方案（号池整体收回重发）(): void
    {
        $rows = self::rows([111 => 'SN-11-0001', 112 => 'SN-11-0002']);

        $assign = SerialService::renumberAssignments(11, 5, $rows);
        $numbers = self::numbers($assign);

        $this->assertCount(2, $numbers);
        $this->assertLessThanOrEqual(5, max($numbers));
        $this->assertSame(2, count(array_unique($numbers)));
    }

    public function test_重编不会再把新号发成资产行ID(): void
    {
        $rows = self::rows([11 => 'SN-2-0011', 12 => 'SN-2-0012', 13 => 'SN-2-0013', 14 => 'SN-2-0014']);
        foreach (SerialService::renumberAssignments(2, 100, $rows) as $id => $serial) {
            $this->assertNotSame($id, (int) SerialService::parseNo($serial), "资产 {$id} 又拿到了自己的行 ID，下一轮巡检还得重编");
        }
    }

    public function test_满载号池也发得不像行ID(): void
    {
        // 行 ID 恰好就是 1..5、发行量也是 5：号池被占满，只能靠错排（derangement）避开各自的行 ID
        $rows = self::rows([1 => 'SN-20-0001', 2 => 'SN-20-0002', 3 => 'SN-20-0003', 4 => 'SN-20-0004', 5 => 'SN-20-0005']);

        $assign = SerialService::renumberAssignments(20, 5, $rows);
        $this->assertSame(range(1, 5), self::numbers($assign), '满载时仍是 1..5 全集');
        foreach ($assign as $id => $serial) {
            $this->assertNotSame($id, (int) SerialService::parseNo($serial));
        }
    }

    public function test_重编后的存量再巡检即判定合规（方案要收敛）(): void
    {
        $cases = [
            // [藏品ID, 发行量, [资产行ID => 旧编号]]
            [2,  100,  [11 => 'SN-2-0011', 12 => 'SN-2-0012', 13 => 'SN-2-0013', 14 => 'SN-2-0014']],
            [1,  1000, [1 => 'SN-1-0001', 2 => 'SN-1-0002', 3 => 'SN-1-0003', 4 => 'SN-1-0004']],
            [20, 5,    [1 => 'SN-20-0001', 2 => 'SN-20-0002', 3 => 'SN-20-0003', 4 => 'SN-20-0004', 5 => 'SN-20-0005']],
            [3,  111,  [15 => 'SN-3-0015', 16 => 'SN-3-0016', 17 => 'SN-3-0017']],
            [9,  300,  [101 => 'TMP-1a2b3c4d5e6f7a8b', 102 => '', 103 => 'SN-9-0500']],
        ];

        foreach ($cases as [$cid, $edition, $pairs]) {
            for ($i = 0; $i < 60; $i++) {
                $rows   = self::rows($pairs);
                $assign = SerialService::renumberAssignments($cid, $edition, $rows);

                $after = [];
                foreach ($assign as $id => $serial) {
                    $after[] = ['id' => $id, 'serial' => $serial];
                }
                $stat = SerialService::analyzeStock($cid, $edition, $after);
                $this->assertTrue($stat['ok'], "藏品 {$cid} 第 " . ($i + 1) . " 次重编后仍被判不合规：" . json_encode($stat['issues'], JSON_UNESCAPED_UNICODE));
            }
        }
    }

    // ---------------------------------------------------------------- D. 命令接线

    public function test_方案只能出自SerialService(): void
    {
        $src = self::commandSource();

        $this->assertStringContainsString('SerialService::analyzeStock(', $src);
        $this->assertStringContainsString('SerialService::renumberAssignments(', $src);
        $this->assertStringContainsString('SerialService::parseNo(', $src);
        // 命令里不许自己拼编号串：拼法只有 SerialService::format 一处
        $this->assertStringNotContainsString("'SN-'", $src, '编号串只能在 SerialService 里拼');
        $this->assertStringNotContainsString('SerialService::format(', $src, '方案已由 SerialService 出到串，命令侧再拼一次就是第二个实现处');
    }

    public function test_默认只读预览不写一行库(): void
    {
        $src = self::commandSource();

        // 预览 + 只读辅助方法都在「按方案落库」分隔线之前
        $parts = explode('==================== 按方案落库', $src);
        $this->assertGreaterThanOrEqual(2, count($parts), '命令要用分隔线把只读半边和写库半边切开');
        $readSide = $parts[0];
        $this->assertStringNotContainsString('->update(', $readSide, '预览分支出现写库调用，等于没有 dry-run');
        $this->assertStringNotContainsString('->insert', $readSide);
        $this->assertStringNotContainsString('->delete(', $readSide);
        $this->assertStringNotContainsString('startTrans', $readSide);
        $this->assertStringContainsString("addOption('apply'", $src, '写库必须显式 --apply');
        $this->assertStringNotContainsString("addOption('dry-run'", $src, '默认就得是只读，别做成默认写库 + --dry-run 兜底');
    }

    public function test_落库前逐行核对现状且默认拒绝(): void
    {
        $src = self::commandSource();

        $this->assertStringContainsString('is_file($from)', $src, '--apply 没给方案文件就拒绝执行');
        $this->assertStringContainsString('方案作废', $src, '发行量/存量/旧号任一有变，整批不动');
        $this->assertStringContainsString('PLAN_VERSION', $src);
        $this->assertStringContainsString('Db::startTrans()', $src);
        $this->assertStringContainsString('Db::rollback()', $src);
    }

    public function test_写号分两段以避开编号唯一索引(): void
    {
        $src = self::commandSource();

        // 先挪到 TMP-R-{资产ID}（parseNo 认不出、不在号池内），再落最终号
        $this->assertStringContainsString("'serial' => 'TMP-R-' . \$id", $src);
        $tmpPos    = strpos($src, "'TMP-R-' . \$id");
        $finalPos  = strpos($src, "where('id', \$id)->update(['serial' => \$new]");
        $commitPos = strpos($src, 'Db::commit()');
        $this->assertNotFalse($finalPos, '第二段写最终号的语句没找到');
        $this->assertLessThan((int) $commitPos, (int) $tmpPos);
        $this->assertLessThan((int) $commitPos, (int) $finalPos);
        $this->assertLessThan((int) $finalPos, (int) $tmpPos, '必须先是占位再是最终号，顺序反了就会撞 uk_collectible_serial');
    }

    public function test_三处编号快照都跟着改(): void
    {
        $src = self::commandSource();

        $this->assertStringContainsString("Db::name('swap_plan_details')->where('user_collectible_id', \$id)->update", $src, '换计划明细按资产行 ID 精确回写');
        $this->assertStringContainsString("Db::name('decompose_records')", $src);
        $this->assertStringContainsString("'source_serial'", $src);
        $this->assertStringContainsString("Db::name('inbox')", $src);
        $this->assertStringContainsString("\$extra['serial']", $src, '通知里的编号存在 extra JSON，要解出来改');
    }

    public function test_重编只写编号列不碰链上凭证与计数器(): void
    {
        $src = self::commandSource();

        preg_match_all('/->update\(\[(.*?)\]\)/s', $src, $m);
        $this->assertNotEmpty($m[1], '命令里应能找到若干回写语句');

        $allowed = ['serial', 'source_serial', 'extra'];
        $written = [];
        foreach ($m[1] as $payload) {
            preg_match_all("/'([a-z_]+)'\s*=>/", $payload, $k);
            foreach ($k[1] as $col) {
                $written[$col] = true;
            }
        }
        $unexpected = array_diff(array_keys($written), $allowed);
        $this->assertSame([], $unexpected, '重编只能写编号列：' . implode('、', $unexpected) . ' 不该被这个命令改动（链上凭证、发行量、计数器都不归它管）');

        // 明确点名几列，防止哪天用变量拼出更新列绕过上检查
        foreach (['tx_hash', 'token_id', 'block_number', 'sold', 'edition', 'circulate'] as $col) {
            $this->assertStringNotContainsString("update(['{$col}'", $src);
        }
    }
}

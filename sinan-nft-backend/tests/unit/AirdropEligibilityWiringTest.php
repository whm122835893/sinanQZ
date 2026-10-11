<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * 空投「保存即圈名单」接线（源码级防漂移，不需要数据库）
 *
 * 缺陷单第 5 条：「全量空投投不了、名单为 0」。根因是保存与圈名单是两步，
 * 而第二步只写在流程说明里，运营点保存以后名单一直是空的，发放又要求
 * 「进行中」+「名单非空」，两个条件同时不满足 —— 于是整条路走不通。
 * 修法是把圈名单收敛成一个私有方法，保存路径自动调一次、手动按钮再调一次。
 *
 * 这类"两条路必须同源"的约束，跑离线单测没法用真实 DB 验，
 * 就直接钉源码：出现第二套筛选用户 / 第二个 5000 上限 / 保存不调用，都算失败。
 */
class AirdropEligibilityWiringTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/admin/controller/MarketingController.php'
        );
    }

    /** 剥掉注释后再数，避免"注释里提了一句"被当成实现 */
    private function code(): string
    {
        // 块注释与行注释一起清掉（本项目注释大量用中文说明，正则是安全的：
        // 代码里不含 /* ，行注释也不会以 // 出现在字符串中间）
        $noBlock = preg_replace('#/\*.*?\*/#s', '', $this->src);
        return preg_replace('#//[^\n]*#', '', (string) $noBlock);
    }

    public function test_圈名单只有一份实现且被调用两次(): void
    {
        $code = $this->code();
        $this->assertSame(1, preg_match_all('/private function regenerateAirdropEligibility\s*\(/', $code), '实现必须只有一份');
        // 定义 1 + 保存自动调 1 + 手动端点调 1
        $this->assertSame(3, preg_match_all('/regenerateAirdropEligibility\s*\(/', $code), '除定义外只能有保存与手动两个调用点');
    }

    public function test_保存活动时会自动圈一次名单且失败不阻断保存(): void
    {
        $code  = $this->code();
        $start = strpos($code, "audit('marketing', 'airdrop_save'");
        $this->assertNotFalse($start, '找不到保存活动的审计语句，说明 airdropSave 结构变了，这条接线测试要跟着改');
        $tail = substr($code, $start, 2000);

        $this->assertStringContainsString('regenerateAirdropEligibility(', $tail, '保存后没有自动圈名单，缺陷会原样复现');
        $this->assertMatchesRegularExpression('/try\s*\{[^}]*regenerateAirdropEligibility\(/s', $tail, '自动圈名单必须包在 try 里');
        $this->assertMatchesRegularExpression('/catch[^\n]*\n?[^}]*\$genError\s*=/', $tail, '圈失败要把原因接住，不能把已存好的活动报成保存失败');
        $this->assertStringContainsString("'eligibilityError'", $tail, '失败原因要回传给前端，页面才说得出为什么名单是空的');
    }

    public function test_手动生成端点不再自己筛用户(): void
    {
        $code = $this->code();
        // 整份文件里"未删除 + 非黑名单"这条口径只许出现一次（就在唯一实现里）
        $this->assertSame(1, substr_count($code, "is_blacklisted', 0"), '出现了第二套用户筛选，保存与手动生成的名单会不一致');
        $this->assertSame(1, substr_count($code, '> 5000'), '单次圈定上限只应有一处');
        $this->assertSame(1, substr_count($code, "Db::name('users')->alias('u')"), '用户查询必须只写在唯一实现里');
    }

    public function test_发放仍要求进行中且名单非空(): void
    {
        // 这两条是置灰原因的依据，后端不放松，前端只负责把它说清楚
        $code = $this->code();
        $this->assertMatchesRegularExpression("/\\\$act\['status'\]\s*!==\s*'active'/", $code);
        $this->assertStringContainsString('没有待发放的用户', $code);
    }
}

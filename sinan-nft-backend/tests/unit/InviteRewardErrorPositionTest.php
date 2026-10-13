<?php
declare(strict_types=1);

namespace tests\unit;

use app\admin\controller\MarketingController;
use app\service\RewardGrantService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 邀请活动保存报错（MarketingController::inviteSave 的奖励校验）
 *
 * 起因：2026-10-13 你报「邀请活动保存显示未知类型」。
 * 根因有两条，都在探针里复现过（qa/probe_invite_reward_readonly.php）：
 *   1. 后台「奖励类型」下拉里有「无奖励」(none)，但后端 RewardGrantService::TYPES 里没有它，
 *      normalizeRewards 直接把它交给 validate()，于是抛出「未知奖励类型 none」——
 *      运营看到的就是这句「未知类型」，而且发放侧同样不认它，就算存进去也会在结算时炸。
 *   2. 报错不带位置。两档八项奖励里有一项不对，也只有光秃秃一句「藏品 #999999 不存在」，
 *      没人知道是哪一档哪一位。
 *
 * 口径（你定的「只改邀请的这一套」）：
 *   - 后端只在邀请这条路上换成 normalizeInviteRewards()，报错带上「哪一档 · 第几位（类型）」；
 *   - 「无奖励」从邀请的下拉里摘掉，后端再兜一道，报成人话；
 *   - 签到 / 注册 / 抽奖 / 合成共用的 ActivityRewardService::normalizeRewards() 一个字没动。
 *
 * 这里测的是不依赖 HTTP 与数据库的纯校验分支（引用类校验要连库，留给 --testsuite api）。
 */
class InviteRewardErrorPositionTest extends TestCase
{
    /**
     * 反射调用私有校验方法：它只依赖静态服务，不需要框架容器，
     * 也就不该为了测试把它改成 public。
     */
    private static function check(): callable
    {
        $ref = new ReflectionClass(MarketingController::class);
        self::assertTrue($ref->hasMethod('normalizeInviteRewards'), '邀请保存应走自己的报错版校验，不再直接调共用的 normalizeRewards');
        $method = $ref->getMethod('normalizeInviteRewards');
        // 不写 setAccessible()：PHP 8.1 起它就是空操作，8.5 还把它列进弃用清单
        $obj = $ref->newInstanceWithoutConstructor();
        return static fn (array $rewards, string $where): array => $method->invoke($obj, $rewards, $where);
    }

    private static function messageOf(callable $check, array $rewards, string $where): string
    {
        try {
            $out = $check($rewards, $where);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $this->fail('本应拦下，却通过了：' . json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------ 1. 「无奖励」不再是未知类型

    public function test_无奖励报的是一句能照着做的话(): void
    {
        $msg = self::messageOf(self::check(), [['type' => 'none']], '邀请 3 人档');
        $this->assertStringNotContainsString('未知奖励类型', $msg, '「未知类型」就是这次要消灭的那句');
        $this->assertStringContainsString('邀请 3 人档', $msg, '要说是哪一档');
        $this->assertStringContainsString('无奖励', $msg, '要说这是哪个类型');
        $this->assertMatchesRegularExpression('/改成具体类型|删掉这一项/', $msg, '要给下一步能做的事');
    }

    public function test_正常奖励仍然通过且被规范化(): void
    {
        $out = (self::check())([['type' => 'points', 'amount' => 20]], '邀请 3 人档');
        $this->assertSame([['rewardType' => 'points', 'amount' => 20.0]], $out, '通过的形状要和共用 normalizeRewards 一致，别改库存字段');
    }

    // ------------------------------------------------ 2. 报错带位置

    public function test_多项时报错指出第几个奖励位(): void
    {
        $check = self::check();
        $msg = self::messageOf($check, [
            ['type' => 'points', 'amount' => 20],
            ['type' => 'points', 'amount' => 0],
        ], '邀请 5 人档');
        $this->assertStringContainsString('邀请 5 人档', $msg);
        $this->assertStringContainsString('第 2 个奖励位', $msg, '两项里的第二项出错，必须说是第 2 位');
        $this->assertStringContainsString('司南币', $msg, '要说这一位配的是哪种奖励');
    }

    public function test_只有一项时不必硬说第几个奖励位(): void
    {
        $msg = self::messageOf(self::check(), [['type' => 'collectible']], '被邀请人奖励');
        $this->assertStringNotContainsString('第 1 个奖励位', $msg, '被邀请人奖励只有一项，写「第 1 个」是凑字数');
        $this->assertStringContainsString('被邀请人奖励（藏品空投）', $msg);
        $this->assertStringContainsString('藏品奖励必须指定 collectibleId', $msg, '后端原始原因要原样带出来，不能只换个说法');
    }

    public function test_空类型与空奖励位各有一条说得清的话(): void
    {
        $check = self::check();
        $this->assertStringContainsString('至少配置一项奖励', self::messageOf($check, [], '邀请 1 人档'));
        $this->assertStringContainsString('奖励类型不能为空', self::messageOf($check, [['amount' => 10]], '邀请 1 人档'));
    }

    public function test_十项上限仍然生效且带上位置(): void
    {
        $many = array_fill(0, 11, ['type' => 'points', 'amount' => 1]);
        $msg = self::messageOf(self::check(), $many, '邀请 2 人档');
        $this->assertStringContainsString('最多配置 10 项奖励', $msg);
        $this->assertStringContainsString('邀请 2 人档', $msg);
    }

    // ------------------------------------------------ 3. 只改邀请这一套（防漂移）

    public function test_邀请保存不再直接调用共用校验(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/admin/controller/MarketingController.php');
        $start = strpos($src, 'public function inviteSave');
        $end   = strpos($src, 'private function normalizeInviteRewards');
        $this->assertNotFalse($start, 'inviteSave 不在了，这条防漂移测试要跟着改');
        $this->assertNotFalse($end, 'normalizeInviteRewards 不在了，报错带位置的修法就丢了');

        // 剥掉注释再查：新写的辅助方法头上一句「为什么不改 normalizeRewards」是说明，不是调用点
        $body = self::stripComments(substr($src, $start, $end - $start));

        $this->assertStringNotContainsString('ActivityRewardService::normalizeRewards', $body, '邀请档位奖励要走带位置的版本');
        $this->assertStringNotContainsString('RewardGrantService::validate', $body, '被邀请人奖励也要走带位置的版本');
        $this->assertSame(2, substr_count($body, '$this->normalizeInviteRewards('), '一个给档位、一个给被邀请人奖励，不该再多出第三处');
    }

    /** 块注释与行注释一起清掉（本项目注释大量用中文说明，正则是安全的） */
    private static function stripComments(string $code): string
    {
        $noBlock = preg_replace('#/\*.*?\*/#s', '', $code);
        return (string) preg_replace('#//[^\n]*#', '', (string) $noBlock);
    }

    public function test_共用的normalizeRewards没有被改动过形状(): void
    {
        // 本轮口径只改邀请：共用方法必须还是那份不带位置的实现，签到/注册/抽奖的报错才不会跟着变
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/service/ActivityRewardService.php');
        $this->assertMatchesRegularExpression(
            '/public static function normalizeRewards.*?\n    \}/s',
            $src,
            '共用校验还在（若哪天要统一带位置报错，那是另一批，需要单独确认）'
        );
    }

    public function test_后台邀请下拉的类型和后端认的一致(): void
    {
        $vue = (string) file_get_contents(dirname(__DIR__, 3) . '/sinan-admin/src/views/marketing/Invite.vue');
        $this->assertMatchesRegularExpression('/const INVITE_REWARD_TYPES = \[(.*?)\]/s', $vue, '邀请页应有一份自己的类型白名单');
        preg_match('/const INVITE_REWARD_TYPES = \[(.*?)\]/s', $vue, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1], $keys);

        $this->assertSame(
            [],
            array_diff($keys[1], RewardGrantService::TYPES),
            '下拉里的类型必须都在后端 TYPES 里，否则又是「未知奖励类型」'
        );
        $this->assertNotContains('none', $keys[1], '「无奖励」不该出现在邀请的可选项里');
        $this->assertSame(2, substr_count($vue, ':types="INVITE_REWARD_TYPES"'), '档位和被邀请人奖励两处都要收窄');
    }
}

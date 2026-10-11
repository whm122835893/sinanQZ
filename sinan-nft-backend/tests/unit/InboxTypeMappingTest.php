<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\InboxService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 到账通知分类（InboxService 的纯映射部分，无 DB）
 *
 * 分类决定"用户看到的那句提示算不算数"：把开盒写成 airdrop、把签到奖励写成空投，
 * 事后在数据层面就分不开了，所以这层映射要钉死。
 * 场景映射的坑在于 ActivityRewardService::SCENE_MAP 把 synthesis 映到了 'airdrop'，
 * 只看 scene 会把合成奖励误标成空投，必须结合 activityType 一起判。
 */
class InboxTypeMappingTest extends TestCase
{
    public function test_五类通知取值与枚举一致(): void
    {
        // 与 migrations/20261011_inbox_arrival_types.sql 的 enum 成员一一对应，
        // 两边不一致时严格模式下写入会 1265 Data truncated，通知静默丢失
        $this->assertSame(['airdrop', 'transfer', 'blindbox', 'reward', 'lottery'], InboxService::TYPES);
        foreach (InboxService::TYPES as $t) {
            $this->assertArrayHasKey($t, InboxService::TITLES, "类型 {$t} 必须有默认标题");
        }
    }

    #[DataProvider('sceneCases')]
    public function test_发放场景映射到通知类型(string $scene, array $context, string $expected, string $why): void
    {
        $this->assertSame($expected, InboxService::typeForGrant($scene, $context), $why);
    }

    public static function sceneCases(): array
    {
        return [
            ['checkin',    ['activityType' => 'checkin'],    'reward',  '签到奖励归入活动奖励'],
            ['register',   ['activityType' => 'register'],   'reward',  '注册福利归入活动奖励'],
            ['invite',     ['activityType' => 'invite'],     'reward',  '邀请奖励归入活动奖励'],
            // SCENE_MAP 把 synthesis 映成 'airdrop'：只看 scene 就会误标为空投
            ['airdrop',    ['activityType' => 'synthesis'],  'reward',  '合成奖励不能被标成空投（scene 是被映射成 airdrop 的）'],
            ['lucky_draw', ['activityType' => 'lucky_draw'], 'lottery', '抽奖单独成类：前端据此当场标记已读'],
            ['lucky_draw', [],                               'lottery', '只有 scene 是 lucky_draw 也判为抽奖'],
            ['airdrop',    [],                               'airdrop', '无活动来源的纯空投沿用 airdrop'],
        ];
    }
}

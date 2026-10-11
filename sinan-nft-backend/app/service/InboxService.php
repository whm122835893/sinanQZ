<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 到账通知（nft_inbox）统一写入口
 *
 * 读端只有一个：C 端 GET /api/inbox/pending（app/controller/Inbox.php）→ 全局弹窗
 * sinan-art-source/src/components/InboxPopup.vue，每 15s 轮询一次，逐条确认。
 *
 * 写端此前是各业务点手搓 insertAll，只有空投两处 + 转赠一处，取值也只有
 * airdrop / transfer 两枚。本类把写入收敛成一个入口，并新增三枚取值
 * （blindbox / reward / lottery，DDL 见 migrations/20261011_inbox_arrival_types.sql）。
 *
 * 三条约定，改代码前先看：
 *  1. **通知失败绝不能拖垮业务写入**。所有方法内部 try/catch 到底、只记日志、返回 0，
 *     调用方不需要（也不应该）为它写异常分支。弹窗少一次是体验问题，发放回滚是资损问题。
 *  2. **只给藏品到账发通知**。nft_inbox.collectible_id 是 NOT NULL 且带外键，
 *     弹窗卡片必须有一件藏品才渲染得出来。积分（司南币）到账走钱包流水，已在别处写。
 *  3. **用户当场已经看到结果的（开盒、抽奖），接口把 inboxId 回传，前端立刻标记已读**：
 *     记录留档、后台可查、不重复弹窗。签到/注册/邀请/统一发放这类"人不在现场"的到账保持未读，
 *     由 15s 轮询弹出来。
 *
 * 所有方法必须在调用方事务内执行：通知与发放要么一起成功，要么一起回滚。
 */
class InboxService
{
    /** 与 nft_inbox.type 枚举一一对应（严格一致：写进枚举外的值在严格模式下会 1265 报错） */
    public const TYPES = ['airdrop', 'transfer', 'blindbox', 'reward', 'lottery'];

    /** 各类型的默认标题（调用方可显式覆盖） */
    public const TITLES = [
        'airdrop'  => '恭喜你收到空投藏品',
        'transfer' => '恭喜你收到转赠藏品',
        'blindbox' => '恭喜你开出藏品',
        'reward'   => '恭喜你获得活动奖励',
        'lottery'  => '恭喜你抽中奖品',
    ];

    /**
     * 奖励发放场景 → 通知类型（RewardGrantService::grantCollectible 用）
     *
     * 抽奖单独成类：中奖弹窗与开盒一样是"当场看结果"，前端按类型决定是否即时消费；
     * 其余（签到 / 注册 / 邀请 / 合成奖励 / 后台统一发放）一律归入 reward。
     */
    public static function typeForGrant(string $scene, array $context = []): string
    {
        $activityType = (string) ($context['activityType'] ?? '');
        if ($activityType === 'lucky_draw' || $scene === 'lucky_draw') {
            return 'lottery';
        }
        // 只有"没有活动来源的纯空投"才沿用 airdrop，避免 synthesis→airdrop 的场景映射把奖励标成空投
        if ($scene === 'airdrop' && $activityType === '') {
            return 'airdrop';
        }
        return 'reward';
    }

    /**
     * 写一条藏品到账通知
     *
     * @param int    $userId     收件用户
     * @param string $type       self::TYPES 之一；不在表内时降级为 reward（宁可分类粗，也不要丢通知）
     * @param array  $collectible 藏品行（取 id / name / image 做快照）
     * @param array  $extra      业务补充信息，如 {quantity, reason, serial, activityName}
     * @param int    $refId      关联业务ID（任务/转赠/奖项/活动），0 表示无
     * @param string $title      自定义标题，留空则用该类型默认标题
     * @return int   新行 ID；0 表示未写入（只记日志，不抛异常）
     */
    public static function pushCollectible(int $userId, string $type, array $collectible, array $extra = [], int $refId = 0, string $title = ''): int
    {
        try {
            if ($userId < 1) {
                return 0;
            }
            if (!in_array($type, self::TYPES, true)) {
                $type = 'reward';
            }
            $cid = (int) ($collectible['id'] ?? $collectible['collectible_id'] ?? 0);
            if ($cid < 1) {
                // collectible_id NOT NULL + 外键：没有藏品主键就写不出合法行，直接放弃并留痕
                Log::error('[inbox] skip: 缺少藏品主键 uid=' . $userId . ' type=' . $type);
                return 0;
            }

            $now = date('Y-m-d H:i:s.v');
            return (int) Db::name('inbox')->insertGetId([
                'user_id'        => $userId,
                'type'           => $type,
                'ref_id'         => $refId > 0 ? $refId : 0,
                'title'          => mb_substr($title !== '' ? $title : self::TITLES[$type], 0, 100),
                'collectible_id' => $cid,
                'name'           => mb_substr((string) ($collectible['name'] ?? ''), 0, 100),
                'image'          => mb_substr((string) ($collectible['image'] ?? ''), 0, 500),
                'extra'          => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                'status'         => 0,
                'created_at'     => $now,
            ]);
        } catch (\Throwable $e) {
            // 见类注释第 1 条：这里吞掉异常是有意的，发放本身不能被通知带崩
            Log::error('[inbox] push failed uid=' . $userId . ' type=' . $type . ' err=' . $e->getMessage());
            return 0;
        }
    }
}

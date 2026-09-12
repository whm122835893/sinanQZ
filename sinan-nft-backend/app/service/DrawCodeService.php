<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 抽签码（用户资产）发放服务
 *
 * 抽签码是参与抽签后发放给用户的凭证，可累积；来源：
 *   1 报名（基础码，每人每活动 1 个）
 *   2 邀请（活动维度：每邀请 N 名好友参与该活动得 1 码，上限可配；另有注册邀请的通用码）
 *   3 购买（单价与上限可配）
 *   4 后台手动新增/导入
 * 归属：活动码（绑定某活动，仅在该活动开奖时入池）/ 通用码（activity_id 为空，
 *   如邀请注册奖励，用户报名任意活动时均作为加成抽签球，中签后全局消耗）
 * 单用户码总量 = 基础 1 + 邀请上限（开关开时）+ 购买上限（开关开时）
 * 状态：1未使用 2已报名 3已失效 4已中签
 */
class DrawCodeService
{
    public const SOURCE_RAFFLE   = 1;
    public const SOURCE_INVITE   = 2;
    public const SOURCE_PURCHASE = 3;
    public const SOURCE_ADMIN    = 4; // 后台手动新增/导入

    public const STATUS_UNUSED     = 1; // 未使用
    public const STATUS_REGISTERED = 2; // 已报名
    public const STATUS_INVALID    = 3; // 已失效（作废）
    public const STATUS_WON        = 4; // 已中签（开奖时中签的码标记）

    /**
     * 发放一个抽签码，返回码串（调用方须处于事务内，靠 uk_code 唯一索引兜底防碰撞）
     */
    public static function grant(int $userId, int $source, ?int $activityId = null, ?string $customCode = null, ?string $remark = null): string
    {
        if ($customCode !== null && $customCode !== '') {
            $code = $customCode;
            if (Db::name('user_draw_codes')->where('code', $code)->find()) {
                throw new \RuntimeException("抽签码 {$code} 已存在");
            }
        } else {
            do {
                $code = gen_draw_code();
            } while (Db::name('user_draw_codes')->where('code', $code)->find());
        }

        Db::name('user_draw_codes')->insert([
            'user_id'     => $userId,
            'code'        => $code,
            'source'      => $source,
            'activity_id' => $activityId,
            'status'      => self::STATUS_UNUSED,
            'remark'      => $remark,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $code;
    }

    /**
     * 用户在某活动按来源统计的有效抽签码数（不含已失效）
     */
    public static function countActivityCodesBySource(int $userId, int $activityId, int $source): int
    {
        if (!self::tableExists()) return 0;
        return (int) Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->where('activity_id', $activityId)
            ->where('source', $source)
            ->where('status', '<>', self::STATUS_INVALID)
            ->count();
    }

    /**
     * 购买抽签码校验：开关开启 + 未超购买上限（buy_code_limit）
     *
     * @param array $activity 抽签活动行（须含 draw_code_enabled / buy_code_limit / id）
     * @param int   $qty      本次拟购买数量
     * @throws \RuntimeException 不可购买/达到上限时抛出，消息可直接透出给前端
     */
    public static function assertCanBuy(int $userId, array $activity, int $qty): void
    {
        if ((int) ($activity['draw_code_enabled'] ?? 0) !== 1) {
            throw new \RuntimeException('当前活动未开放购买抽签码');
        }
        $limit = (int) ($activity['buy_code_limit'] ?? 0);
        if ($limit <= 0) {
            throw new \RuntimeException('当前活动未配置购买抽签码上限');
        }
        $bought = self::countActivityCodesBySource($userId, (int) $activity['id'], self::SOURCE_PURCHASE);
        if ($bought + $qty > $limit) {
            throw new \RuntimeException("购买抽签码上限 {$limit} 个，您已购买 {$bought} 个");
        }
    }

    /**
     * 邀请好友参与抽签得码结算（幂等，须在报名事务内调用）
     *
     * 规则（invite_enabled=1 时生效）：
     * - 被邀请人（inviteeId）首次报名本活动时触发；
     * - 通过注册邀请关系（invite_records）找到邀请人，为邀请人结算（settleForInviter）。
     *
     * @return int 本次实际发放的邀请码数量
     */
    public static function settleInviteRewards(array $activity, int $inviteeId): int
    {
        if ((int) ($activity['invite_enabled'] ?? 0) !== 1) return 0;

        // 被邀请人的邀请人（注册邀请关系）
        $inviterId = (int) (Db::name('invite_records')->where('invitee_id', $inviteeId)->value('inviter_id') ?? 0);
        if ($inviterId <= 0 || $inviterId === $inviteeId) return 0;

        return self::settleForInviter($activity, $inviterId);
    }

    /**
     * 为指定邀请人结算邀请奖励（幂等，须在报名事务内调用）
     *
     * 邀请人自己报名时也会调用本方法补发（好友先于邀请人参与的场景），
     * 保证无论报名先后顺序，应得码数不漏发：
     * - 邀请人须已参与本活动（未参与不结算；自己报名的事务内已插入报名记录，视为已参与）；
     * - 每累计 invite_user_needed 名被邀请好友参与，得 1 码（SOURCE_INVITE，绑定本活动）；
     * - 总量封顶 invite_code_limit；按「应得 = min(⌊参与人数/门槛⌋, 上限)」与已得差值补发；
     * - 邀请人报名行加锁串行化同一邀请人的并发结算，防多名好友同时报名时漏发。
     *
     * @return int 本次实际发放的邀请码数量
     */
    public static function settleForInviter(array $activity, int $inviterId): int
    {
        if ((int) ($activity['invite_enabled'] ?? 0) !== 1) return 0;
        if ($inviterId <= 0) return 0;

        $activityId = (int) $activity['id'];

        // 邀请人须已参与本活动（先无锁探测：行不存在直接返回，避免对空行 FOR UPDATE 产生间隙锁）
        $exists = Db::name('raffle_registrations')
            ->where('activity_id', $activityId)
            ->where('user_id', $inviterId)
            ->find();
        if (!$exists) return 0;

        // 行锁串行化同一邀请人的结算（记录锁，无间隙锁死锁风险）
        Db::name('raffle_registrations')
            ->where('activity_id', $activityId)
            ->where('user_id', $inviterId)
            ->lock(true)
            ->find();

        // 邀请人名下已参与本活动的被邀请好友数
        $invitedCount = (int) Db::name('raffle_registrations')->alias('reg')
            ->join('invite_records ir', 'ir.invitee_id = reg.user_id', 'INNER')
            ->where('reg.activity_id', $activityId)
            ->where('ir.inviter_id', $inviterId)
            ->count();

        $needed = max(1, (int) ($activity['invite_user_needed'] ?? 1));
        $limit  = (int) ($activity['invite_code_limit'] ?? 0);
        $earned = self::countActivityCodesBySource($inviterId, $activityId, self::SOURCE_INVITE);
        $due    = min(intdiv($invitedCount, $needed), $limit);
        $grantN = $due - $earned;
        if ($grantN <= 0) return 0;

        for ($i = 0; $i < $grantN; $i++) {
            self::grant($inviterId, self::SOURCE_INVITE, $activityId, null, '邀请好友参与抽签奖励');
        }
        return $grantN;
    }

    /**
     * 用户在某活动的邀请进度（C 端展示）
     * @return array{invited: int, rewarded: int, limit: int, needed: int}
     */
    public static function inviteProgress(int $userId, array $activity): array
    {
        $activityId = (int) $activity['id'];
        return [
            'invited'  => (int) Db::name('raffle_registrations')->alias('reg')
                ->join('invite_records ir', 'ir.invitee_id = reg.user_id', 'INNER')
                ->where('reg.activity_id', $activityId)
                ->where('ir.inviter_id', $userId)
                ->count(),
            'rewarded' => self::countActivityCodesBySource($userId, $activityId, self::SOURCE_INVITE),
            'limit'    => (int) ($activity['invite_code_limit'] ?? 0),
            'needed'   => max(1, (int) ($activity['invite_user_needed'] ?? 1)),
        ];
    }

    /**
     * 用户某活动未失效的抽签码数量
     */
    public static function activityCodeCount(int $userId, int $activityId): int
    {
        if (!self::tableExists()) return 0;
        return (int) Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->where('activity_id', $activityId)
            ->where('status', '<>', self::STATUS_INVALID)
            ->count();
    }

    /**
     * 用户持有抽签码总数（表未迁移时优雅降级为 0，避免详情页 500）
     */
    public static function count(int $userId): int
    {
        if (!self::tableExists()) return 0;
        return (int) Db::name('user_draw_codes')->where('user_id', $userId)->count();
    }

    /**
     * 某活动内用户获得的抽签码（报名发放）
     */
    public static function activityCodes(int $userId, int $activityId): array
    {
        if (!self::tableExists()) return [];
        return Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->where('activity_id', $activityId)
            ->order('id', 'asc')
            ->column('code');
    }

    /**
     * 用户与本活动开奖相关的抽签码明细（本活动码 + 通用码），含中签标记
     * @return array<int, array{code: string, won: bool}>
     */
    public static function activityCodeList(int $userId, int $activityId): array
    {
        if (!self::tableExists()) return [];
        $rows = Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->whereRaw('(activity_id = ' . (int) $activityId . ' OR activity_id IS NULL)')
            ->where('status', '<>', self::STATUS_INVALID)
            ->order('id', 'asc')
            ->field('code, status')
            ->select()->toArray();
        return array_map(fn ($r) => [
            'code' => (string) $r['code'],
            'won'  => (int) $r['status'] === self::STATUS_WON,
        ], $rows);
    }

    /**
     * 用户全部抽签码（码号列表，按发放时间正序）
     */
    public static function codes(int $userId): array
    {
        if (!self::tableExists()) return [];
        return Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->order('id', 'asc')
            ->column('code');
    }

    /**
     * 用户报名时将其在该活动的「未使用」码标记为「已报名」
     */
    public static function markRegistered(int $userId, int $activityId): void
    {
        if (!self::tableExists()) return;
        Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->where('activity_id', $activityId)
            ->where('status', self::STATUS_UNUSED)
            ->update(['status' => self::STATUS_REGISTERED]);
    }

    /**
     * 作废一个抽签码（status → 3）
     */
    public static function invalidate(int $codeId, string $reason = ''): bool
    {
        $row = Db::name('user_draw_codes')->where('id', $codeId)->find();
        if (!$row) return false;
        if ((int) $row['status'] === self::STATUS_INVALID) return false;
        Db::name('user_draw_codes')->where('id', $codeId)->update([
            'status'     => self::STATUS_INVALID,
            'remark'     => trim(($row['remark'] ?? '') . ' 作废:' . ($reason ?: date('Y-m-d H:i:s'))),
        ]);
        return true;
    }

    /**
     * 资产表是否存在（进程内缓存判断一次）
     */
    private static function tableExists(): bool
    {
        static $exists = null;
        if ($exists === null) {
            try {
                Db::query('SELECT 1 FROM `nft_user_draw_codes` LIMIT 1');
                $exists = true;
            } catch (\Throwable $e) {
                $exists = false;
            }
        }
        return $exists;
    }
}

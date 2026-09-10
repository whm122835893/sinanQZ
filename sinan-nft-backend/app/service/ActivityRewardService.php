<?php
declare(strict_types=1);

namespace app\service;

use app\service\RewardGrantService;
use app\service\RewardGrantException;
use think\facade\Db;
use think\facade\Log;

/**
 * 活动奖励编排服务（五大活动统一收敛）
 *
 * 职责：
 * 1. 参与资格判定：all/realname/checkin/invite/hold/checkin_rank（配置 JSON）
 * 2. 奖励发放编排：realtime 实时到账（RewardGrantService）/ manual 记录名单（activity_reward_records）
 * 3. 注册活动结算：实名通过 → 实名排位 → 命中档位（实名前N名）
 * 4. 邀请活动结算：被邀请人完成条件（实名/钱包/签到/消费）→ 邀请人档位 + 被邀请人奖励
 * 5. 待发放名单统一发放：后台 issuePendingRecords
 *
 * 供管理端配置校验与 C 端各发放点（实名审核/签到/抽奖/充值/支付）复用。
 * 所有方法须在调用方事务内执行（发放路径）。
 */
class ActivityRewardService
{
    /** 参与资格类型全集 */
    public const ELIGIBILITY_TYPES = ['all', 'realname', 'checkin', 'invite', 'hold', 'checkin_rank'];

    /** 被邀请人完成条件类型全集 */
    public const INVITEE_CONDITIONS = ['realname', 'wallet', 'checkin', 'consume'];

    /** 活动类型全集（奖励名单表 activity_type） */
    public const ACTIVITY_TYPES = ['synthesis', 'lucky_draw', 'checkin', 'invite', 'register'];

    /** 活动类型 → 场景（RewardGrantService::SCENE_QUOTA） */
    private const SCENE_MAP = [
        'synthesis'  => 'airdrop',
        'lucky_draw' => 'lucky_draw',
        'checkin'    => 'checkin',
        'invite'     => 'invite',
        'register'   => 'register',
    ];

    // =====================================================================
    // 一、参与资格判定
    // =====================================================================

    /**
     * 判定用户是否满足活动参与资格
     *
     * @param int      $userId 用户ID
     * @param string   $type   资格类型（self::ELIGIBILITY_TYPES）
     * @param array|null $config 资格配置 JSON：
     *   checkin     {days: N}                累计签到 ≥ N 天（默认1）
     *   invite      {count: N}               累计邀请 ≥ N 人（默认1）
     *   hold        {collectibleIds:[], match:'any'|'all'}  持有藏品
     *   checkin_rank {rank: N}               签到前 N 名（按首次签到时间排序）
     * @return array{eligible:bool, reason:string}
     */
    public static function checkEligibility(int $userId, string $type, ?array $config): array
    {
        $config = $config ?: [];

        switch ($type) {
            case 'all':
                return ['eligible' => true, 'reason' => ''];

            case 'realname':
                $ok = (int) Db::name('users')->where('id', $userId)->value('is_realname') === 1;
                return $ok
                    ? ['eligible' => true, 'reason' => '']
                    : ['eligible' => false, 'reason' => '该活动仅限已完成实名认证的用户参与'];

            case 'checkin':
                $days = max(1, (int) ($config['days'] ?? 1));
                $done = (int) Db::name('check_in_records')->where('user_id', $userId)->count();
                return $done >= $days
                    ? ['eligible' => true, 'reason' => '']
                    : ['eligible' => false, 'reason' => "该活动需累计签到 {$days} 天后参与（当前 {$done} 天）"];

            case 'invite':
                $count = max(1, (int) ($config['count'] ?? 1));
                $done  = (int) Db::name('invite_records')->where('inviter_id', $userId)->where('status', 'registered')->count();
                return $done >= $count
                    ? ['eligible' => true, 'reason' => '']
                    : ['eligible' => false, 'reason' => "该活动需累计邀请 {$count} 人后参与（当前 {$done} 人）"];

            case 'hold':
                $ids = array_values(array_filter(array_map('intval', (array) ($config['collectibleIds'] ?? []))));
                if (!$ids) {
                    return ['eligible' => false, 'reason' => '活动未配置持有藏品要求'];
                }
                $match = ($config['match'] ?? 'any') === 'all' ? 'all' : 'any';
                $held = Db::name('user_collectibles')
                    ->where('user_id', $userId)
                    ->whereIn('collectible_id', $ids)
                    ->where('status', 'held')
                    ->field('collectible_id, COUNT(*) AS cnt')
                    ->group('collectible_id')
                    ->select()->toArray();
                $heldIds = array_map(fn ($r) => (int) $r['collectible_id'], $held);
                if ($match === 'all') {
                    $ok = !array_diff($ids, $heldIds);
                } else {
                    $ok = (bool) array_intersect($ids, $heldIds);
                }
                return $ok
                    ? ['eligible' => true, 'reason' => '']
                    : ['eligible' => false, 'reason' => '该活动需持有指定藏品后参与'];

            case 'checkin_rank':
                $rank = max(1, (int) ($config['rank'] ?? 100));
                $firstAt = Db::name('check_in_records')->where('user_id', $userId)->min('created_at');
                if (!$firstAt) {
                    return ['eligible' => false, 'reason' => "该活动限签到前 {$rank} 名用户参与，您还未签到"];
                }
                // 排位 = 首次签到时间早于当前用户的去重用户数 + 1
                $ahead = (int) Db::name('check_in_records')
                    ->field('user_id, MIN(created_at) AS first_at')
                    ->group('user_id')
                    ->having('first_at < ' . strtotime((string) $firstAt))
                    ->count();
                $myRank = $ahead + 1;
                return $myRank <= $rank
                    ? ['eligible' => true, 'reason' => '']
                    : ['eligible' => false, 'reason' => "该活动限签到前 {$rank} 名用户参与（您的签到排位第 {$myRank} 名）"];

            default:
                return ['eligible' => false, 'reason' => '未知参与资格类型 ' . $type];
        }
    }

    /**
     * 校验资格配置（管理端保存用）
     *
     * @return array{type:string, config:?array}
     * @throws \Exception 配置不合法
     */
    public static function validateEligibility(string $type, array $config): array
    {
        if (!in_array($type, self::ELIGIBILITY_TYPES, true)) {
            throw new \Exception('参与资格类型仅允许：' . implode('/', self::ELIGIBILITY_TYPES));
        }
        $clean = [];
        switch ($type) {
            case 'checkin':
                $days = (int) ($config['days'] ?? 1);
                if ($days < 1 || $days > 3650) {
                    throw new \Exception('累计签到天数需在 1~3650');
                }
                $clean = ['days' => $days];
                break;
            case 'invite':
                $count = (int) ($config['count'] ?? 1);
                if ($count < 1 || $count > 10000) {
                    throw new \Exception('累计邀请人数需在 1~10000');
                }
                $clean = ['count' => $count];
                break;
            case 'hold':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($config['collectibleIds'] ?? [])))));
                if (!$ids) {
                    throw new \Exception('持有藏品资格需至少指定一个藏品');
                }
                if (count($ids) > 50) {
                    throw new \Exception('持有藏品列表最多 50 个');
                }
                $exists = Db::name('collectibles')->whereIn('id', $ids)->whereNull('deleted_at')->column('id');
                if (count($exists) !== count($ids)) {
                    throw new \Exception('持有藏品列表中存在无效藏品');
                }
                $clean = ['collectibleIds' => $ids, 'match' => ($config['match'] ?? 'any') === 'all' ? 'all' : 'any'];
                break;
            case 'checkin_rank':
                $rank = (int) ($config['rank'] ?? 100);
                if ($rank < 1 || $rank > 1000000) {
                    throw new \Exception('签到前N名需在 1~1000000');
                }
                $clean = ['rank' => $rank];
                break;
        }
        return ['type' => $type, 'config' => $clean ?: null];
    }

    // =====================================================================
    // 二、奖励配置校验与文案
    // =====================================================================

    /**
     * 校验并规范化奖励列表（管理端配置保存用）
     *
     * 输入：[{type:'collectible', collectibleId:1, quantity:2}, {type:'points', amount:100}, ...]
     * @return array 规范化后的奖励列表
     * @throws \Exception 校验失败
     */
    public static function normalizeRewards(array $rewards): array
    {
        if (!$rewards) {
            throw new \Exception('至少配置一项奖励');
        }
        if (count($rewards) > 10) {
            throw new \Exception('单个奖励位最多配置 10 项奖励');
        }
        $result = [];
        foreach ($rewards as $reward) {
            // 兼容两种输入：管理端原始格式（type）与已规范化存储格式（rewardType）
            $type = (string) ($reward['type'] ?? $reward['rewardType'] ?? '');
            $result[] = RewardGrantService::validate($type, $reward);
        }
        return $result;
    }

    /** 奖励类型中文标签 */
    public const TYPE_LABELS = [
        'collectible'               => '藏品空投',
        'points'                    => '司南币',
        'draw_chance'               => '抽奖次数',
        'priority_qualification'    => '优先购资格',
        'eligibility_qualification' => '资格购白名单',
        'blindbox'                  => '盲盒',
        'none'                      => '无奖励',
    ];

    /**
     * 奖励描述文案（名单导出展示）
     */
    public static function rewardLabel(array $reward): string
    {
        $type = (string) ($reward['rewardType'] ?? $reward['type'] ?? 'none');
        $label = self::TYPE_LABELS[$type] ?? $type;
        switch ($type) {
            case 'collectible':
                $name = Db::name('collectibles')->where('id', (int) $reward['collectibleId'])->value('name');
                return $label . '「' . ($name ?: '#' . $reward['collectibleId']) . '」×' . (int) ($reward['quantity'] ?? 1);
            case 'blindbox':
                return $label . '×' . (int) ($reward['quantity'] ?? 1);
            case 'points':
                return $label . ' ' . (float) $reward['amount'];
            case 'draw_chance':
                return $label . ' ×' . (int) ($reward['quantity'] ?? 1);
            case 'priority_qualification':
                return $label . '（可购 ' . (int) ($reward['quantity'] ?? 1) . ' 份）';
            case 'eligibility_qualification':
                return $label . '（目标藏品 #' . (int) $reward['collectibleId'] . '）';
            default:
                return $label;
        }
    }

    // =====================================================================
    // 三、奖励发放编排（realtime 实时到账 / manual 记录名单）
    // =====================================================================

    /**
     * 发放一组奖励（须在调用方事务内执行）
     *
     * @param array  $rewards       normalizeRewards() 规范化后的奖励列表
     * @param int    $userId        接收用户
     * @param string $activityType  活动类型（self::ACTIVITY_TYPES）
     * @param int    $activityId    活动ID
     * @param string $activityTitle 活动名称快照
     * @param string $grantMode     realtime 实时到账 / manual 记录名单统一发放
     * @param array  $context       ['slot'=>奖励位标识(档位防重), 'title'=>描述, 'relatedId'=>来源业务ID]
     * @return array ['mode'=>..., 'issued'=>bool, 'results'=>[], 'records'=>[]]
     */
    public static function grantRewards(array $rewards, int $userId, string $activityType, int $activityId, string $activityTitle, string $grantMode, array $context = []): array
    {
        $slot   = (string) ($context['slot'] ?? 'default');
        $phone  = (string) Db::name('users')->where('id', $userId)->value('phone');
        $scene  = self::SCENE_MAP[$activityType] ?? 'airdrop';
        $title  = (string) ($context['title'] ?? $activityTitle);

        if ($grantMode === 'manual') {
            // ---- 记录名单：去重键 = 活动+用户+奖励位 ----
            $records = [];
            foreach ($rewards as $reward) {
                $dedupeKey = self::buildDedupeKey($activityType, $activityId, $userId, $slot . ':' . $reward['rewardType']);
                $exists = Db::name('activity_reward_records')->where('dedupe_key', $dedupeKey)->find();
                if ($exists) {
                    continue;
                }
                $records[] = Db::name('activity_reward_records')->insertGetId([
                    'activity_type'  => $activityType,
                    'activity_id'    => $activityId,
                    'activity_title' => mb_substr($activityTitle, 0, 100),
                    'user_id'        => $userId,
                    'phone'          => $phone,
                    'reward_type'    => $reward['rewardType'],
                    'reward_config'  => json_encode($reward, JSON_UNESCAPED_UNICODE),
                    'reward_label'   => self::rewardLabel($reward),
                    'status'         => 'pending',
                    'dedupe_key'     => $dedupeKey,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            }
            return ['mode' => 'manual', 'issued' => false, 'results' => [], 'records' => $records];
        }

        // ---- realtime：实时到账（去重键防重复入账）----
        $results = [];
        foreach ($rewards as $reward) {
            $dedupeKey = self::buildDedupeKey($activityType, $activityId, $userId, $slot . ':' . $reward['rewardType']);
            if (Db::name('activity_reward_records')->where('dedupe_key', $dedupeKey)->find()) {
                continue; // 已发放过，跳过（档位幂等）
            }
            $result = RewardGrantService::grant(
                $reward['rewardType'],
                $reward,
                $userId,
                $scene,
                [
                    'title'        => $title,
                    'activityId'   => $activityId,
                    'activityType' => $activityType,
                    'relatedId'    => $context['relatedId'] ?? null,
                    'luckyDrawActivityId' => $context['luckyDrawActivityId'] ?? null,
                ]
            );
            // realtime 也落名单（status=issued，供后台导出对账）
            Db::name('activity_reward_records')->insert([
                'activity_type'  => $activityType,
                'activity_id'    => $activityId,
                'activity_title' => mb_substr($activityTitle, 0, 100),
                'user_id'        => $userId,
                'phone'          => $phone,
                'reward_type'    => $reward['rewardType'],
                'reward_config'  => json_encode($reward, JSON_UNESCAPED_UNICODE),
                'reward_label'   => self::rewardLabel($reward),
                'status'         => 'issued',
                'issue_result'   => '实时到账',
                'issued_at'      => date('Y-m-d H:i:s'),
                'dedupe_key'     => $dedupeKey,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            $results[] = $result;
        }
        return ['mode' => 'realtime', 'issued' => true, 'results' => $results, 'records' => []];
    }

    /**
     * 待发放名单统一发放（后台执行；内部独立事务，逐条隔离，单条失败不阻断）
     *
     * @param string $activityType 活动类型（空=全部）
     * @param int    $activityId   活动ID（0=全部）
     * @param array  $recordIds    指定记录ID（空=全部 pending）
     * @return array ['success'=>n, 'failed'=>n, 'messages'=>[]]
     */
    public static function issuePendingRecords(string $activityType = '', int $activityId = 0, array $recordIds = []): array
    {
        $query = Db::name('activity_reward_records')->where('status', 'pending');
        if ($activityType !== '' && in_array($activityType, self::ACTIVITY_TYPES, true)) {
            $query->where('activity_type', $activityType);
        }
        if ($activityId > 0) {
            $query->where('activity_id', $activityId);
        }
        if ($recordIds) {
            $query->whereIn('id', array_map('intval', $recordIds));
        }
        $rows = $query->order('id', 'asc')->limit(1000)->select()->toArray();

        $success = 0; $failed = 0; $messages = [];
        foreach ($rows as $row) {
            Db::startTrans();
            try {
                $reward = json_decode((string) $row['reward_config'], true) ?: [];
                $reward['rewardType'] = $row['reward_type'];
                $result = RewardGrantService::grant(
                    $row['reward_type'],
                    $reward,
                    (int) $row['user_id'],
                    self::SCENE_MAP[$row['activity_type']] ?? 'airdrop',
                    ['title' => $row['activity_title'], 'activityId' => (int) $row['activity_id'], 'activityType' => $row['activity_type']]
                );
                Db::name('activity_reward_records')->where('id', $row['id'])->update([
                    'status'       => 'issued',
                    'issue_result' => '统一发放成功',
                    'issued_at'    => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
                Db::commit();
                $success++;
            } catch (\Throwable $e) {
                Db::rollback();
                Db::name('activity_reward_records')->where('id', $row['id'])->update([
                    'status'       => 'failed',
                    'issue_result' => mb_substr($e->getMessage(), 0, 255),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
                $failed++;
                $messages[] = '#' . $row['id'] . ' ' . $e->getMessage();
            }
        }
        return ['success' => $success, 'failed' => $failed, 'messages' => $messages];
    }

    /** 构建去重键：活动+用户+奖励位 */
    private static function buildDedupeKey(string $activityType, int $activityId, int $userId, string $slot): string
    {
        return $activityType . ':' . $activityId . ':u' . $userId . ':' . $slot;
    }

    /**
     * 独立事务执行结算回调（奖励发放不阻断主业务）
     *
     * - 独立调用：开启新事务；
     * - 嵌套调用（外层已有事务）：自动降级为 savepoint，回滚仅撤销结算写入；
     * - 任一异常：回滚 + 记日志 + 吞掉，主业务（签到/充值/支付/实名审核）不受影响。
     */
    public static function settleQuietly(callable $fn): void
    {
        try {
            Db::startTrans();
            try {
                $fn();
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error('[活动奖励结算失败] ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            // 事务降级失败等极端情况：静默兜底
        }
    }

    // =====================================================================
    // 四、注册活动结算（实名通过 → 实名排位 → 档位命中）
    // =====================================================================

    /**
     * 注册实名结算（实名审核通过后调用；须在调用方事务内执行）
     *
     * 取启用中的注册活动 → 计算用户实名排位（realname_verified_at 升序）
     * → 命中最小满足档位（rankLimit ≥ 排位）→ 发放/记录
     *
     * @return array|null 命中结果 ['rank'=>.., 'tier'=>..] 或 null
     */
    public static function settleRegisterReward(int $userId): ?array
    {
        $act = Db::name('register_activities')
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                // MK-D3 修复：where() 不支持第 4 个逻辑参数（生成悬空 OR 导致 SQL 1064，
                // 实名审核结算被 settleQuietly 吞掉，邀请/注册奖励从未发放），改用 whereOr
                $q->whereNull('start_time')->whereOr('start_time', '<=', date('Y-m-d H:i:s'));
            })
            ->where(function ($q) {
                $q->whereNull('end_time')->whereOr('end_time', '>=', date('Y-m-d H:i:s'));
            })
            ->order('id', 'desc')
            ->find();
        if (!$act) {
            return null;
        }

        $tiers = json_decode((string) ($act['tiers'] ?? ''), true) ?: [];
        if (!$tiers) {
            return null;
        }
        // 档位按 rankLimit 升序，取命中的最小档位
        usort($tiers, fn ($a, $b) => (int) $a['rankLimit'] <=> (int) $b['rankLimit']);

        // 实名排位：实名通过时间早于当前用户的用户数 + 1
        $me = Db::name('users')->where('id', $userId)->field('realname_verified_at, is_realname')->find();
        if (!$me || (int) $me['is_realname'] !== 1 || !$me['realname_verified_at']) {
            return null;
        }
        $ahead = (int) Db::name('users')
            ->where('is_realname', 1)
            ->whereNull('deleted_at')
            ->where('realname_verified_at', '<', $me['realname_verified_at'])
            ->count();
        $rank = $ahead + 1;

        foreach ($tiers as $tier) {
            if ($rank <= (int) ($tier['rankLimit'] ?? 0)) {
                $rewards = self::normalizeRewards((array) ($tier['rewards'] ?? []));
                self::grantRewards(
                    $rewards, $userId, 'register', (int) $act['id'],
                    (string) $act['name'], (string) ($act['grant_mode'] ?? 'realtime'),
                    ['slot' => 'rank' . $tier['rankLimit'], 'title' => $act['name'] . '（实名排位第 ' . $rank . ' 名）']
                );
                Db::name('register_activities')->where('id', $act['id'])->update([
                    'used_count' => Db::raw('used_count + 1'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return ['rank' => $rank, 'tier' => $tier];
            }
        }
        return null;
    }

    // =====================================================================
    // 五、邀请活动结算（被邀请人完成条件 → 双方奖励）
    // =====================================================================

    /**
     * 判定被邀请人是否满足完成条件（任一满足即通过）
     *
     * @param array $conditions 条件数组：realname实名/wallet开通第三方钱包(完成充值)/checkin签到/consume消费
     */
    public static function inviteeConditionsMet(int $inviteeId, array $conditions): bool
    {
        $conditions = array_values(array_intersect($conditions, self::INVITEE_CONDITIONS));
        if (!$conditions) {
            return true; // 未配置条件 = 无条件
        }
        foreach ($conditions as $cond) {
            switch ($cond) {
                case 'realname':
                    if ((int) Db::name('users')->where('id', $inviteeId)->value('is_realname') === 1) {
                        return true;
                    }
                    break;
                case 'wallet':
                    if (Db::name('wallet_transactions')->where('user_id', $inviteeId)->where('trans_type', 'recharge')->count() > 0) {
                        return true;
                    }
                    break;
                case 'checkin':
                    if (Db::name('check_in_records')->where('user_id', $inviteeId)->count() > 0) {
                        return true;
                    }
                    break;
                case 'consume':
                    if (Db::name('orders')->where('user_id', $inviteeId)->where('status', 'completed')->count() > 0) {
                        return true;
                    }
                    break;
            }
        }
        return false;
    }

    /**
     * 邀请结算（被邀请人完成条件后调用；须在调用方事务内执行）
     *
     * 1. 被邀请人奖励：每人一次（slot=invitee）
     * 2. 邀请人档位奖励：有效邀请数达到档位（1-50人）逐档发放，每档一次（slot=tier{n}）
     *
     * @param int $inviteeId 被邀请人用户ID
     * @return array ['settled'=>bool, 'inviterGranted'=>[]]
     */
    public static function settleInviteReward(int $inviteeId): array
    {
        $record = Db::name('invite_records')->where('invitee_id', $inviteeId)->find();
        if (!$record) {
            return ['settled' => false, 'inviterGranted' => []];
        }
        $inviterId = (int) $record['inviter_id'];

        // 取启用中的邀请活动（有档位配置的新版活动）
        $act = Db::name('invite_activities')
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->where('tiers', '<>', '')
            ->whereNotNull('tiers')
            ->where(function ($q) {
                // MK-D3 修复：同上，whereOr 替代第 4 参（悬空 OR SQL 1064）
                $q->whereNull('end_time')->whereOr('end_time', '>=', date('Y-m-d H:i:s'));
            })
            ->order('id', 'desc')
            ->find();
        if (!$act) {
            return ['settled' => false, 'inviterGranted' => []];
        }

        $tiers = json_decode((string) ($act['tiers'] ?? ''), true) ?: [];
        $conditions = json_decode((string) ($act['invitee_conditions'] ?? ''), true) ?: [];
        $grantMode = (string) ($act['grant_mode'] ?? 'realtime');

        // 被邀请人条件判定
        if (!self::inviteeConditionsMet($inviteeId, $conditions)) {
            return ['settled' => false, 'inviterGranted' => []];
        }

        $granted = [];

        // ---- 1. 被邀请人奖励（每人一次）----
        $inviteeReward = json_decode((string) ($act['invitee_reward_config'] ?? ''), true);
        // 兼容已规范化存储格式（rewardType）与原始格式（type）
        $inviteeType = (string) ($inviteeReward['type'] ?? $inviteeReward['rewardType'] ?? '');
        if ($inviteeReward && $inviteeType !== '') {
            $reward = RewardGrantService::validate($inviteeType, $inviteeReward);
            self::grantRewards(
                [$reward], $inviteeId, 'invite', (int) $act['id'],
                (string) $act['name'], $grantMode,
                ['slot' => 'invitee', 'title' => $act['name'] . '·被邀请人奖励', 'relatedId' => (int) $record['id']]
            );
            $granted['invitee'] = true;
        }

        // ---- 2. 邀请人档位奖励（有效邀请数 ≥ 档位人数，每档一次）----
        if ($tiers) {
            usort($tiers, fn ($a, $b) => (int) ($a['inviteCount'] ?? 0) <=> (int) ($b['inviteCount'] ?? 0));
            $validCount = self::countValidInvitees($inviterId, $conditions);
            foreach ($tiers as $tier) {
                $inviteCount = (int) ($tier['inviteCount'] ?? 0);
                if ($inviteCount < 1 || $inviteCount > 50) {
                    continue;
                }
                if ($validCount >= $inviteCount && !empty($tier['rewards'])) {
                    $rewards = self::normalizeRewards((array) $tier['rewards']);
                    self::grantRewards(
                        $rewards, $inviterId, 'invite', (int) $act['id'],
                        (string) $act['name'], $grantMode,
                        ['slot' => 'tier' . $inviteCount, 'title' => $act['name'] . '·有效邀请 ' . $inviteCount . ' 人档位奖励', 'relatedId' => (int) $record['id']]
                    );
                    $granted['tier' . $inviteCount] = true;
                }
            }
        }

        return ['settled' => true, 'inviterGranted' => $granted];
    }

    /**
     * 统计邀请人有效邀请数（被邀请人满足完成条件）
     */
    private static function countValidInvitees(int $inviterId, array $conditions): int
    {
        $conditions = array_values(array_intersect($conditions, self::INVITEE_CONDITIONS));
        if (!$conditions) {
            return (int) Db::name('invite_records')
                ->where('inviter_id', $inviterId)
                ->where('status', 'registered')
                ->count();
        }
        // TP ORM 的 OR EXISTS 不便表达 → 拉全量被邀请人逐个判定（邀请量级 ≤ 数百，可接受）
        $inviteeIds = Db::name('invite_records')
            ->where('inviter_id', $inviterId)
            ->where('status', 'registered')
            ->column('invitee_id');
        $valid = 0;
        foreach ($inviteeIds as $iid) {
            if (self::inviteeConditionsMet((int) $iid, $conditions)) {
                $valid++;
            }
        }
        return $valid;
    }

    // =====================================================================
    // 六、活动有效性校验（C 端入口共用）
    // =====================================================================

    /**
     * 活动是否在有效窗口内
     */
    public static function inWindow(?string $startTime, ?string $endTime): bool
    {
        $now = date('Y-m-d H:i:s');
        if ($startTime !== null && $startTime !== '' && $startTime > $now) {
            return false;
        }
        if ($endTime !== null && $endTime !== '' && $endTime < $now) {
            return false;
        }
        return true;
    }

    /**
     * 解析 JSON 字段（兼容 null/空串/已解码数组）
     */
    public static function parseJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

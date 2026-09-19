<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\ActivityRewardService;
use think\facade\Db;

/**
 * 签到控制器（多活动模型）
 *
 * 后台"新建活动"模式：nft_check_in_activities 支持多条活动，同时只有
 * 最新一条"启用中且在时间窗内"的活动生效（奖励/资格/发放方式均取自该活动）。
 *
 * 模块开关 checkin_enabled：
 * - 关闭 → C端签到页空状态（activity() 返回 enabled=false）
 * - 开启但未配置活动 → 页面显示，activity() 返回 enabled=true + activity=null，签到返回 4003
 */
class CheckIn extends BaseController
{
    /**
     * GET /api/check-in/activity（公开）
     * C端签到页数据源：模块开关 + 当前生效活动
     */
    public function activity()
    {
        $enabled = (int) $this->cfg('checkin_enabled') === 1;

        $activity = $enabled ? $this->activeActivity() : null;

        $data = ['enabled' => $enabled, 'activity' => null];
        if ($activity) {
            $rewardConfig = json_decode((string) ($activity['reward_config'] ?? ''), true) ?: [];
            $data['activity'] = [
                'id'           => (int) $activity['id'],
                'name'         => (string) $activity['name'],
                'startTime'    => (string) $activity['start_time'],
                'endTime'      => $activity['end_time'] !== null ? (string) $activity['end_time'] : null,
                'rewardDays'   => array_map('intval', array_keys($rewardConfig)),
                'rewardConfig' => $rewardConfig ?: new \stdClass(),
                'eligibilityType' => (string) $activity['eligibility_type'],
                'grantMode'    => (string) $activity['grant_mode'],
            ];
        }
        return $this->success($data);
    }

    /**
     * 当前生效的签到活动：启用中 + 未删除 + 在时间窗内，取最新一条
     */
    private function activeActivity(): ?array
    {
        $now = date('Y-m-d H:i:s');
        return Db::name('check_in_activities')
            ->whereNull('deleted_at')
            ->where('status', 'enabled')
            ->where('start_time', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('end_time')->whereOr('end_time', '>=', $now);
            })
            ->order('id', 'desc')
            ->find();
    }

    /**
     * POST /api/check-in
     * 每日签到（奖励取当前生效活动：资格判定 → 六类奖励统一发放）
     */
    public function perform()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $today = date('Y-m-d');
        $todayRecord = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->where('check_in_date', $today)
            ->find();
        if ($todayRecord) {
            return $this->success([
                'already' => true,
                'day'     => (int) $todayRecord['consecutive_days'],
                'reward'  => [
                    'type'        => $todayRecord['reward_type'],
                    'amount'      => (int) $todayRecord['reward_amount'],
                    'description' => $todayRecord['reward_description'],
                ],
            ]);
        }

        // 模块开关
        if ((int) $this->cfg('checkin_enabled') !== 1) {
            return $this->fail(4003, '签到功能暂未开放');
        }

        // 当前生效活动
        $activity = $this->activeActivity();
        if (!$activity) {
            return $this->fail(4003, '暂无进行中的签到活动');
        }

        // 计算连续天数
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $prev      = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->where('check_in_date', $yesterday)
            ->find();
        $streak = $prev ? (int) $prev['consecutive_days'] + 1 : 1;

        $now = date('Y-m-d H:i:s.v');
        Db::startTrans();
        try {
            $reward = ['type' => 'none', 'amount' => 0, 'relatedId' => null, 'description' => '无奖励'];

            $rewardConfig = json_decode((string) ($activity['reward_config'] ?? ''), true) ?: [];
            $actName = (string) $activity['name'];

            if (isset($rewardConfig[$streak]) && is_array($rewardConfig[$streak]) && $rewardConfig[$streak]) {
                // 资格判定
                $eligibility = ActivityRewardService::checkEligibility(
                    $userId,
                    (string) $activity['eligibility_type'],
                    json_decode((string) ($activity['eligibility_config'] ?? ''), true) ?: []
                );
                if (!$eligibility['eligible']) {
                    $reward['description'] = '今日已签到，但不符合活动参与资格：' . $eligibility['reason'];
                } else {
                    $rewards = ActivityRewardService::normalizeRewards($rewardConfig[$streak]);
                    ActivityRewardService::grantRewards(
                        $rewards,
                        $userId,
                        'checkin',
                        (int) $activity['id'],
                        $actName,
                        (string) $activity['grant_mode'],
                        [
                            'slot'  => 'day' . $streak,
                            'title' => $actName . '（连续签到第' . $streak . '天）',
                        ]
                    );
                    $first = $rewards[0];
                    $reward = [
                        'type'        => $first['rewardType'],
                        'amount'      => (int) ($first['amount'] ?? $first['quantity'] ?? 0),
                        'relatedId'   => $first['collectibleId'] ?? $first['prioritySaleId'] ?? $first['blindboxId'] ?? null,
                        'description' => implode('；', array_map(
                            fn ($r) => ActivityRewardService::rewardLabel($r),
                            $rewards
                        )),
                    ];
                }
            }

            Db::name('check_in_records')->insert([
                'user_id'             => $userId,
                'activity_id'         => (int) $activity['id'],
                'check_in_date'       => $today,
                'consecutive_days'    => $streak,
                'reward_type'         => $reward['type'],
                'reward_amount'       => $reward['amount'],
                'reward_related_id'   => $reward['relatedId'],
                'reward_description'  => mb_substr($reward['description'], 0, 255),
                'created_at'          => $now,
            ]);

            // 活动累计签到人次
            Db::name('check_in_activities')->where('id', (int) $activity['id'])
                ->inc('signin_count')->update();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '签到失败：' . $e->getMessage());
        }

        // 签到完成 → 被邀请人完成条件（checkin）邀请活动结算（失败不影响签到）
        ActivityRewardService::settleQuietly(
            fn () => ActivityRewardService::settleInviteReward($userId)
        );

        return $this->success([
            'day'     => $streak,
            'already' => false,
            'reward'  => [
                'type'        => $reward['type'],
                'amount'      => $reward['amount'],
                'description' => $reward['description'],
            ],
        ]);
    }

    /**
     * GET /api/check-in/records
     * 签到记录列表
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $records = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->order('check_in_date', 'desc')
            ->limit(90)
            ->select()
            ->toArray();

        return $this->success(array_map(function ($r) {
            return [
                'date'         => $r['check_in_date'],
                'day'          => (int) $r['consecutive_days'],
                'rewardType'   => $r['reward_type'],
                'rewardAmount' => (int) $r['reward_amount'],
                'description'  => $r['reward_description'],
            ];
        }, $records));
    }

    /**
     * GET /api/check-in/calendar?month=2026-09
     * 日历视图（某月签到日期 + 连续天数）
     */
    public function calendar()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $month = (string) $this->request->param('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->fail(4220, 'month 格式应为 YYYY-MM');
        }

        $days = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->whereLike('check_in_date', $month . '%')
            ->order('check_in_date', 'asc')
            ->select()
            ->toArray();

        $currentStreak = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->where('check_in_date', date('Y-m-d'))
            ->value('consecutive_days');

        return $this->success([
            'month'   => $month,
            'current' => $currentStreak !== null ? (int) $currentStreak : 0,
            'days'    => array_map(function ($r) {
                return [
                    'date' => $r['check_in_date'],
                    'day'  => (int) $r['consecutive_days'],
                ];
            }, $days),
        ]);
    }

    /** 读取系统配置 */
    private function cfg(string $key): string
    {
        return (string) Db::name('system_configs')->where('config_key', $key)->value('config_value');
    }
}

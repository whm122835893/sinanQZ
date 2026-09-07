<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;
use app\service\ActivityRewardService;

use think\facade\Db;

/**
 * 签到控制器
 */
class CheckIn extends BaseController
{
    /**
     * POST /api/check-in
     * 每日签到
     *
     * 奖励链路（新版签到活动）：
     *   checkin_enabled=1 且 checkin_reward_config 配置当日奖励
     *   → 参与资格判定（checkin_eligibility_type/config）
     *   → 统一发放（checkin_grant_mode：realtime 实时到账 / manual 记录名单统一发放）
     * 否则回退旧版：checkin_rewards（连续天数 → 司南币）
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

        // 计算连续天数
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $prev      = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->where('check_in_date', $yesterday)
            ->find();
        $streak = $prev ? (int) $prev['consecutive_days'] + 1 : 1;

        // 活动配置（新版六类奖励 + 资格 + 发放方式；旧版司南币兜底）
        $configs = Db::name('system_configs')
            ->whereIn('config_key', [
                'checkin_enabled', 'checkin_activity_name',
                'checkin_eligibility_type', 'checkin_eligibility_config', 'checkin_grant_mode',
                'checkin_reward_config', 'checkin_rewards',
            ])
            ->column('config_value', 'config_key');

        $newRules = json_decode((string) ($configs['checkin_reward_config'] ?? ''), true) ?: [];
        $useNew   = (int) ($configs['checkin_enabled'] ?? 0) === 1
            && isset($newRules[$streak]) && is_array($newRules[$streak]) && $newRules[$streak];

        $now = date('Y-m-d H:i:s.v');
        Db::startTrans();
        try {
            $reward = ['type' => 'none', 'amount' => 0, 'relatedId' => null, 'description' => '无奖励'];

            if ($useNew) {
                // ---- 新版：资格判定 → 六类奖励统一发放 ----
                $eligibility = ActivityRewardService::checkEligibility(
                    $userId,
                    (string) ($configs['checkin_eligibility_type'] ?? 'all'),
                    json_decode((string) ($configs['checkin_eligibility_config'] ?? ''), true) ?: []
                );
                if (!$eligibility['eligible']) {
                    $reward['description'] = '今日已签到，但不符合活动参与资格：' . $eligibility['reason'];
                } else {
                    $rewards = ActivityRewardService::normalizeRewards($newRules[$streak]);
                    $actName = (string) ($configs['checkin_activity_name'] ?? '每日签到');
                    ActivityRewardService::grantRewards(
                        $rewards,
                        $userId,
                        'checkin',
                        0,
                        $actName,
                        (string) ($configs['checkin_grant_mode'] ?? 'realtime'),
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
            } else {
                // ---- 旧版：连续天数 → 司南币 ----
                $legacy = json_decode((string) ($configs['checkin_rewards'] ?? ''), true) ?: [];
                $amount = (int) ($legacy[$streak] ?? 0);
                if ($amount > 0) {
                    Db::name('wallet_transactions')->insert([
                        'user_id'        => $userId,
                        'trans_type'     => 'reward',
                        'title'          => '签到奖励（连续' . $streak . '天）',
                        'direction'      => 1,
                        'amount'         => $amount,
                        'balance_after'  => (float) Db::name('wallets')->where('user_id', $userId)->value('balance') + $amount,
                        'created_at'     => $now,
                    ]);
                    Db::name('wallets')->where('user_id', $userId)->update([
                        'points'     => Db::raw("points + {$amount}"),
                        'updated_at' => $now,
                    ]);
                    $reward = [
                        'type'        => 'points',
                        'amount'      => $amount,
                        'relatedId'   => null,
                        'description' => "连续签到第{$streak}天奖励 {$amount} 司南币",
                    ];
                }
            }

            Db::name('check_in_records')->insert([
                'user_id'             => $userId,
                'check_in_date'       => $today,
                'consecutive_days'    => $streak,
                'reward_type'         => $reward['type'],
                'reward_amount'       => $reward['amount'],
                'reward_related_id'   => $reward['relatedId'],
                'reward_description'  => mb_substr($reward['description'], 0, 255),
                'created_at'          => $now,
            ]);

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
     * 签到记录（按月）
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $month = $this->request->param('month', date('Y-m'));
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));

        $records = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->whereBetween('check_in_date', [$start, $end])
            ->order('check_in_date', 'desc')
            ->select()
            ->toArray();

        $currentStreak = (int) ($records[0]['consecutive_days'] ?? 0);

        return $this->success([
            'currentStreak' => $currentStreak,
            'records'       => array_map(fn ($r) => [
                'date'        => $r['check_in_date'],
                'rewardType'  => $r['reward_type'],
                'amount'      => (int) $r['reward_amount'],
            ], $records),
        ]);
    }

    /**
     * GET /api/check-in/calendar
     * 签到日历
     */
    public function calendar()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $year  = $this->intParam('year', (int) date('Y'));
        $month = $this->intParam('month', (int) date('m'));

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $days = Db::name('check_in_records')
            ->where('user_id', $userId)
            ->whereBetween('check_in_date', [$start, $end])
            ->column('check_in_date');

        $dayNos = array_map(fn ($d) => (int) substr($d, -2), $days);
        $currentStreak = 0;
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if (in_array($yesterday, $days)) {
            $currentStreak = Db::name('check_in_records')
                ->where('user_id', $userId)
                ->where('check_in_date', $yesterday)
                ->value('consecutive_days') ?? 0;
        }

        return $this->success([
            'days'          => $dayNos,
            'currentStreak' => (int) $currentStreak,
        ]);
    }
}

<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 签到控制器
 */
class CheckIn extends BaseController
{
    /**
     * POST /api/check-in
     * 每日签到
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

        // 查奖励配置
        $config = Db::name('system_configs')
            ->where('config_key', 'checkin_rewards')
            ->value('config_value');
        $rewards = json_decode($config, true) ?: [];
        $amount  = $rewards[$streak] ?? 0;

        $now = date('Y-m-d H:i:s.v');
        Db::startTrans();

        if ($amount > 0) {
            // 发司南币
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
        }

        Db::name('check_in_records')->insert([
            'user_id'             => $userId,
            'check_in_date'       => $today,
            'consecutive_days'    => $streak,
            'reward_type'         => $amount > 0 ? 'points' : 'none',
            'reward_amount'       => $amount,
            'reward_description'  => $amount > 0 ? "连续签到第{$streak}天奖励 {$amount} 司南币" : '无奖励',
            'created_at'          => $now,
        ]);

        Db::commit();

        return $this->success([
            'day'     => $streak,
            'already' => false,
            'reward'  => [
                'type'        => $amount > 0 ? 'points' : 'none',
                'amount'      => $amount,
                'description' => $amount > 0 ? "连续签到第{$streak}天奖励 {$amount} 司南币" : '无奖励',
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

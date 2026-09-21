<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 实名认证领域服务
 *
 * 统一「实名审核通过」的口径（C 端自动通过 / 管理端人工审核共用）：
 * 1. 状态更新：realname_status=2 + is_realname=1（解锁购买/寄售/转赠）
 * 2. 活动结算：注册活动（实名排位）+ 邀请活动（被邀请人完成实名）
 *    独立事务，奖励发放失败不阻断审核（记日志，可在奖励名单中排查）
 */
class RealnameService
{
    /**
     * 审核通过（自动/手动同口径）
     *
     * @param int   $userId      用户ID
     * @param array $extraUpdate 附加更新字段（C 端提交即通过时合并提交数据，
     *                           如 real_name / id_card / realname_submitted_at）
     * @return array{register: array|null, invite: array|null} 活动结算结果
     */
    public static function approve(int $userId, array $extraUpdate = []): array
    {
        $now = date('Y-m-d H:i:s');
        Db::name('users')->where('id', $userId)->update(array_merge($extraUpdate, [
            'realname_status'        => 2,
            'is_realname'            => 1,
            'realname_verified_at'   => $now,
            'realname_reject_reason' => '',
            'updated_at'             => $now,
        ]));

        $settled = ['register' => null, 'invite' => null];
        ActivityRewardService::settleQuietly(function () use ($userId, &$settled) {
            $settled['register'] = ActivityRewardService::settleRegisterReward($userId);
            $settled['invite']   = ActivityRewardService::settleInviteReward($userId);
        });
        return $settled;
    }
}

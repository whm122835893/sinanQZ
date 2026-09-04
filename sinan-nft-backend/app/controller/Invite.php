<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 邀请控制器
 */
class Invite extends BaseController
{
    /**
     * GET /api/invite/info
     * 我的邀请信息
     */
    public function info()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $user = Db::name('users')->where('id', $userId)->find();
        $activity = Db::name('invite_activities')
            ->where('status', 'enabled')
            ->where(function ($q) {
                // 注意：TP 无 whereOrNull 方法（会被误解析为 end_time = `null`），
                // OR 逻辑的 IS NULL 需用 whereNull($field, 'OR')
                $q->where('end_time', '>=', date('Y-m-d H:i:s'))->whereNull('end_time', 'OR');
            })
            ->order('id', 'desc')
            ->find();

        $inviteeCount = Db::name('invite_records')->where('inviter_id', $userId)->count();

        $activityData = null;
        if ($activity) {
            $inviterC = $activity['inviter_collectible_id']
                ? Db::name('collectibles')->where('id', $activity['inviter_collectible_id'])->field('id,name')->find()
                : null;
            $inviteeC = $activity['invitee_collectible_id']
                ? Db::name('collectibles')->where('id', $activity['invitee_collectible_id'])->field('id,name')->find()
                : null;

            $activityData = [
                'activityId'       => (int) $activity['id'],
                'name'             => $activity['name'],
                'enabled'          => $activity['status'] === 'enabled',
                'inviterReward'    => $inviterC ? ['collectibleId' => (int) $inviterC['id'], 'name' => $inviterC['name']] : null,
                'inviteeReward'    => $inviteeC ? ['collectibleId' => (int) $inviteeC['id'], 'name' => $inviteeC['name']] : null,
            ];
        }

        return $this->success([
            'inviteCode'  => $user['invite_code'],
            'activity'    => $activityData,
            'inviteeCount'=> $inviteeCount,
        ]);
    }

    /**
     * GET /api/invite/records
     * 邀请关系列表
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');
        $p = $this->pagination();

        $query = Db::name('invite_records')
            ->alias('ir')
            ->join('users u', 'u.id = ir.invitee_id')
            ->where('ir.inviter_id', $userId);

        $total = $query->count();
        $list  = $query
            ->order('ir.created_at', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'ir.id as record_id', 'ir.status', 'ir.created_at', 'u.phone',
            ])->select()->toArray();

        return $this->paginate(array_map(fn ($r) => [
            'recordId'   => (int) $r['record_id'],
            'inviteePhone' => mask_phone($r['phone']),
            'status'     => $r['status'],
            'createdAt'  => $r['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }
}

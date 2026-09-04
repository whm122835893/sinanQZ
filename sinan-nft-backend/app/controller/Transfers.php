<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 转赠控制器
 */
class Transfers extends BaseController
{
    /**
     * POST /api/transfers
     * 发起转赠
     */
    public function create()
    {
        $userId            = $this->userId();
        $userCollectibleId = $this->intParam('userCollectibleId');
        $toPhone           = $this->request->post('toPhone', '');
        $paymentPassword   = $this->request->post('paymentPassword', '');

        if (!$userId) return $this->fail(2001, '未登录');
        if (!preg_match('/^1\d{10}$/', $toPhone)) return $this->fail(1001, '手机号格式错误');

        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash || !verify_password($paymentPassword, $hash)) {
            return $this->fail(2003, '交易密码错误');
        }

        $toUser = Db::name('users')->where('phone', $toPhone)->find();
        if (!$toUser) return $this->fail(1002, '受赠方尚未注册');

        if ((int) $toUser['id'] === $userId) return $this->fail(1001, '不能转赠给自己');

        Db::startTrans();
        try {
            // 资产校验放入事务并加行锁：防止与寄售/开盒/再转赠并发争抢同一资产
            $uc = Db::name('user_collectibles')
                ->where('id', $userCollectibleId)
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '藏品不可转赠');
            }

            $now = date('Y-m-d H:i:s.v');
            Db::name('transfers')->insert([
                'from_user_id'        => $userId,
                'to_user_id'          => $toUser['id'],
                'to_phone'            => $toPhone,
                'to_nickname'         => $toUser['username'],
                'collectible_id'      => $uc['collectible_id'],
                'user_collectible_id' => $userCollectibleId,
                'status'              => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            Db::name('user_collectibles')->where('id', $userCollectibleId)->update([
                'status'     => 'frozen',
                'updated_at' => $now,
            ]);
            Db::commit();
            return $this->success(['status' => 'pending', 'toPhone' => mask_phone($toPhone)]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '转赠失败：' . $e->getMessage());
        }
    }

    /**
     * POST /api/transfers/:transferId/handle
     * 处理转赠（接受/拒绝）
     */
    public function handle()
    {
        $userId     = $this->userId();
        $transferId = $this->request->param('transferId');
        $action     = $this->request->post('action', '');

        if (!$userId) return $this->fail(2001, '未登录');
        if (!in_array($action, ['accept', 'reject'])) return $this->fail(1001, '操作无效');

        Db::startTrans();
        try {
            // 行锁：防止重复点击/并发双处理（重复接受会重复计资产）
            $transfer = Db::name('transfers')
                ->where('id', $transferId)
                ->where('to_user_id', $userId)
                ->where('status', 'pending')
                ->lock(true)
                ->find();
            if (!$transfer) {
                Db::rollback();
                return $this->fail(1002, '转赠不存在或已处理');
            }

            $now = date('Y-m-d H:i:s.v');
            if ($action === 'accept') {
                Db::name('transfers')->where('id', $transferId)->update([
                    'status'       => 'accepted',
                    'confirmed_at' => $now,
                    'updated_at'   => $now,
                ]);
                // 条件更新：仅当资产仍为 frozen 才转移（防状态机跳变）
                $moved = Db::name('user_collectibles')
                    ->where('id', $transfer['user_collectible_id'])
                    ->where('status', 'frozen')
                    ->update([
                        'user_id'        => $userId,
                        'status'         => 'held',
                        'source'         => 'transfer',
                        'acquired_at'    => $now,
                        'acquired_price' => 0,
                        'is_consigned'   => 0,
                        'updated_at'    => $now,
                    ]);
                if (!$moved) {
                    Db::rollback();
                    return $this->fail(3001, '藏品状态异常，请稍后重试');
                }
            } else {
                Db::name('transfers')->where('id', $transferId)->update([
                    'status'       => 'rejected',
                    'confirmed_at' => $now,
                    'updated_at'   => $now,
                ]);
                Db::name('user_collectibles')
                    ->where('id', $transfer['user_collectible_id'])
                    ->where('status', 'frozen')
                    ->update([
                        'status'     => 'held',
                        'updated_at' => $now,
                    ]);
            }
            Db::commit();
            return $this->success(['status' => $action === 'accept' ? 'accepted' : 'rejected']);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '处理失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/transfers/mine
     * 我的转赠记录
     */
    public function mine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p        = $this->pagination();
        $direction = $this->strParam('direction');
        $status   = $this->strParam('status');

        $query = Db::name('transfers')->alias('t')
            ->join('collectibles c', 'c.id = t.collectible_id')
            ->where('t.from_user_id|t.to_user_id', $userId)
            ->order('t.created_at', 'desc');

        if ($direction === 'sent')      $query->where('t.from_user_id', $userId);
        elseif ($direction === 'received') $query->where('t.to_user_id', $userId);
        if ($status) $query->where('t.status', $status);

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            't.id as transfer_id', 't.status', 't.created_at', 't.from_user_id',
            't.to_phone as counterpart', 'c.name', 'c.image',
            't.user_collectible_id',
        ])->select()->toArray();

        // 查编号
        $ids = array_unique(array_column($list, 'user_collectible_id'));
        $noses = Db::name('user_collectibles')->whereIn('id', $ids)->column('serial', 'id');

        $items = array_map(function ($t) use ($ids, $noses, $userId) {
            return [
                'transferId' => (int) $t['transfer_id'],
                'name'       => $t['name'],
                'image'      => $t['image'],
                'no'         => $noses[$t['user_collectible_id']] ?? '',
                'direction'  => (int) $t['from_user_id'] === $userId ? 'sent' : 'received',
                'status'     => $t['status'],
                'counterpart'=> mask_phone($t['counterpart']),
                'createdAt'  => $t['created_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }
}

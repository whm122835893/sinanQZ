<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台退款管理控制器
 *
 * 退款工作流：发起（订单页）→ 财务审批（approve/reject）→ 执行退款（execute）。
 *
 * 执行退款事务（资金 + 资产 + 库存原子结转）：
 * 1. 退款原路退回：余额支付退余额，三方支付（mock）入账余额并标注渠道
 * 2. 订单资产回收：user_collectibles → recovered（含在售挂单强制下架）
 * 3. 库存回滚：sold -= 数量、circulate -= 数量（回冲库存池，保持恒等式）
 * 4. 状态机：order → refunded、payment → refunded、refund → 已退款(3)
 */
class RefundController extends BaseController
{
    /**
     * GET /admin/refund/list
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('refunds')->alias('r');

        $refundNo = trim((string) $this->request->param('refundNo', ''));
        if ($refundNo !== '') {
            $query->whereLike('r.refund_no', '%' . $refundNo . '%');
        }
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $query->where('r.status', (int) $status);
        }
        $userId = $this->positiveInt('userId');
        if ($userId !== null) {
            $query->where('r.user_id', $userId);
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('r.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('r.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('r.*, o.order_no, u.uid, u.username, c.name AS collectible_name')
            ->join('orders o', 'o.id = r.order_id', 'LEFT')
            ->join('users u', 'u.id = r.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $result = array_map(function ($row) {
            $row['status_text'] = ['待审批', '已批准', '已退款', '已拒绝'][(int) $row['status'] - 1] ?? '';
            return camelize_keys($row);
        }, $rows);

        return $this->paginate($result, $total, $page, $pageSize);
    }

    /**
     * GET /admin/refund/detail/:id
     */
    public function detail(int $id)
    {
        $refund = Db::name('refunds')->alias('r')
            ->field('r.*, o.order_no, o.collectible_id, o.quantity, o.source AS order_source,
                     u.uid, u.username, c.name AS collectible_name')
            ->join('orders o', 'o.id = r.order_id', 'LEFT')
            ->join('users u', 'u.id = r.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->where('r.id', $id)
            ->find();
        if (!$refund) {
            return $this->fail(4040, '退款单不存在');
        }
        $refund['status_text'] = ['待审批', '已批准', '已退款', '已拒绝'][(int) $refund['status'] - 1] ?? '';

        $payment = Db::name('payments')->where('id', $refund['payment_id'])->find();
        $ucs = Db::name('user_collectibles')->where('order_id', $refund['order_id'])
            ->field('id, serial, status, user_id')->select()->toArray();

        $data = camelize_keys($refund);
        $data['payment'] = $payment ? camelize_keys($payment) : null;
        $data['userCollectibles'] = camelize_keys($ucs);
        return $this->success($data);
    }

    /**
     * POST /admin/refund/approve { id, action(approve/reject), comment }
     */
    public function approve()
    {
        $missing = $this->missingParams(['id', 'action']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $action = (string) $this->request->param('action');
        $comment = trim((string) $this->request->param('comment', ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->fail(4220, 'action 仅允许 approve / reject');
        }

        $refund = Db::name('refunds')->where('id', $id)->where('status', 1)->find();
        if (!$refund) {
            return $this->fail(4220, '退款单不存在或不在待审批状态');
        }
        if ($action === 'reject' && $comment === '') {
            return $this->fail(4220, '拒绝退款必须填写审批意见');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            Db::name('refunds')->where('id', $id)->update([
                'status'        => $action === 'approve' ? 2 : 4,
                'approver_id'   => $this->adminId(),
                'approver_name' => $this->adminName(),
                'approved_at'   => $now,
                'comment'       => mb_substr($comment, 0, 255) ?: null,
                'updated_at'    => $now,
            ]);

            // 拒绝：订单回滚 completed
            if ($action === 'reject') {
                Db::name('orders')->where('id', $refund['order_id'])->where('status', 'refunding')
                    ->update(['status' => 'completed', 'updated_at' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '审批失败：' . $e->getMessage());
        }

        $this->audit('refund', 'approve_' . $action,
            ($action === 'approve' ? '批准' : '拒绝') . '退款 ' . $refund['refund_no'],
            ['comment' => $comment], 'refund', $id);
        return $this->success(null, $action === 'approve' ? '退款已批准，请执行退款操作' : '退款已拒绝，订单恢复完成状态');
    }

    /**
     * POST /admin/refund/execute { id, refund_channel? }
     * 执行退款（资金退回 + 资产回收 + 库存回滚，单一事务）
     */
    public function execute()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        Db::startTrans();
        try {
            $refund = Db::name('refunds')->where('id', $id)->where('status', 2)->lock(true)->find();
            if (!$refund) {
                Db::rollback();
                return $this->fail(4220, '退款单不存在或未处于已批准状态');
            }
            $order = Db::name('orders')->where('id', $refund['order_id'])->lock(true)->find();
            if (!$order) {
                throw new \Exception('关联订单不存在');
            }

            $now = date('Y-m-d H:i:s');

            // 1. 资产回收：强制下架在售挂单 + 持仓置 recovered
            $ucs = Db::name('user_collectibles')->where('order_id', $order['id'])
                ->whereIn('status', ['held', 'consigned', 'frozen'])
                ->select()->toArray();
            foreach ($ucs as $uc) {
                if ($uc['status'] === 'consigned') {
                    $listing = Db::name('resale_listings')->where('user_collectible_id', $uc['id'])->where('status', 'selling')->find();
                    if ($listing) {
                        Db::name('resale_listings')->where('id', $listing['id'])->update([
                            'status' => 'cancelled', 'is_system_delisted' => 1,
                            'system_delisted_at' => $now, 'delist_reason' => '退款资产回收', 'updated_at' => $now,
                        ]);
                    }
                }
                Db::name('user_collectibles')->where('id', $uc['id'])->update([
                    'status' => 'recovered', 'is_consigned' => 0, 'updated_at' => $now,
                ]);
            }
            $recoveredQty = count($ucs);

            // 2. 库存回滚：sold -= recovered（回冲库存池）、circulate -= recovered
            if ($recoveredQty > 0) {
                Db::name('collectibles')->where('id', $order['collectible_id'])->update([
                    'sold'      => Db::raw('GREATEST(0, sold - ' . $recoveredQty . ')'),
                    'circulate' => Db::raw('GREATEST(0, circulate - ' . $recoveredQty . ')'),
                    'updated_at' => $now,
                ]);
            }

            // 3. 资金退回：入账用户余额（余额支付原路退回；三方支付本环境入账余额并留渠道备注）
            $wallet = Db::name('wallets')->where('user_id', $refund['user_id'])->lock(true)->find();
            if (!$wallet) {
                Db::name('wallets')->insert([
                    'user_id' => $refund['user_id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                $wallet = Db::name('wallets')->where('user_id', $refund['user_id'])->find();
            }
            $channel = trim((string) $this->request->param('refund_channel', '')) ?: $refund['refund_no'];
            Db::name('wallets')->where('user_id', $refund['user_id'])->update([
                'balance'    => Db::raw("balance + {$refund['amount']}"),
                'available'  => Db::raw("available + {$refund['amount']}"),
                'updated_at' => $now,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $refund['user_id'],
                'trans_type'    => 'reward',
                'title'         => '订单退款入账',
                'direction'     => 1,
                'amount'        => $refund['amount'],
                'balance_after' => (float) $wallet['balance'] + (float) $refund['amount'],
                'biz_no'        => $refund['refund_no'],
                'created_at'    => $now,
            ]);

            // 4. 状态机收口
            Db::name('payments')->where('id', $refund['payment_id'])->update([
                'status' => 'refunded', 'updated_at' => $now,
            ]);
            Db::name('orders')->where('id', $order['id'])->update([
                'status' => 'refunded', 'updated_at' => $now,
            ]);
            Db::name('refunds')->where('id', $id)->update([
                'status' => 3, 'refunded_at' => $now,
                'refund_channel' => mb_substr((string) $channel, 0, 50),
                'updated_at' => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '退款执行失败：' . $e->getMessage());
        }

        $this->audit('refund', 'execute', '执行退款 ' . $refund['refund_no'] . '（金额 ' . $refund['amount'] . '，回收资产 ' . $recoveredQty . ' 份）',
            ['recovered' => $recoveredQty], 'refund', $id);
        return $this->success(null, '退款完成：资金已入账、资产已回收、库存已回滚');
    }
}

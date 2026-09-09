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

        $query = Db::name('refunds')->alias('r')
            ->join('orders o', 'o.id = r.order_id', 'LEFT')
            ->join('users u', 'u.id = r.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT');

        $refundNo = trim((string) $this->request->param('refundNo', ''));
        if ($refundNo !== '') {
            $query->whereLike('r.refund_no', '%' . $refundNo . '%');
        }
        // 状态筛选：兼容语义值（前端枚举）与数字（1待审批/2已批准/3已退款/4已拒绝）
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $statusMap = ['pending' => 1, 'approved' => 2, 'refunded' => 3, 'rejected' => 4];
            $statusVal = $statusMap[(string) $status] ?? (int) $status;
            if ($statusVal > 0) {
                $query->where('r.status', $statusVal);
            }
        }
        $userId = $this->positiveInt('userId');
        if ($userId !== null) {
            $query->where('r.user_id', $userId);
        }
        // 关键词搜索：退款单号 / 订单号 / 用户（AdminTablePage 统一发送 keyword）
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('r.refund_no', '%' . $keyword . '%')
                    ->whereOr('o.order_no', 'like', '%' . $keyword . '%')
                    ->whereOr('u.username', 'like', '%' . $keyword . '%')
                    ->whereOr('u.uid', 'like', '%' . $keyword . '%');
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('r.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('r.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('r.*, o.order_no, u.uid, u.username, u.phone, c.name AS collectible_name, c.image AS collectible_image')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $result = array_map(function ($row) {
            $row['status_text'] = ['待审批', '已批准', '已退款', '已拒绝'][(int) $row['status'] - 1] ?? '';
            $row['phone'] = $row['phone'] ? mask_phone((string) $row['phone']) : null;
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

        // 大额退款审批中心复核：金额 ≥ 阈值时不直接批准，生成审批单
        // （驳回不受金额限制，可直接驳回；发起人 = 当前审批人，复核人须为他人）
        if ($action === 'approve') {
            $threshold = (float) (Db::name('system_configs')
                ->where('config_key', 'large_refund_approval_threshold')->value('config_value') ?: 1000);
            if ((float) $refund['amount'] >= $threshold) {
                $exists = Db::name('approval_requests')
                    ->where('target_type', 'refund')->where('target_id', $id)
                    ->where('status', 1)->count();
                if ($exists === 0) {
                    $order = Db::name('orders')->where('id', $refund['order_id'])->find();
                    $approvalNo = 'AP' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
                    Db::name('approval_requests')->insert([
                        'approval_no'    => $approvalNo,
                        'type'           => 'large_refund',
                        'title'          => '大额退款复核：' . $refund['refund_no'] . '（' . $refund['amount'] . ' 元）',
                        'detail'         => json_encode([
                            'refund_no'   => $refund['refund_no'],
                            'order_no'    => $order['order_no'] ?? '',
                            'amount'      => $refund['amount'],
                            'reason'      => $refund['reason'],
                            'applicant'   => $this->adminName(),
                        ], JSON_UNESCAPED_UNICODE),
                        'amount'         => (float) $refund['amount'],
                        'target_type'    => 'refund',
                        'target_id'      => $id,
                        'applicant_id'   => $this->adminId(),
                        'applicant_name' => $this->adminName(),
                        'status'         => 1,
                        'created_at'     => date('Y-m-d H:i:s'),
                        'updated_at'     => date('Y-m-d H:i:s'),
                    ]);
                    $this->audit('refund', 'approval_submit',
                        '大额退款 ' . $refund['refund_no'] . '（' . $refund['amount'] . ' 元）已提交审批中心复核',
                        ['approval_threshold' => $threshold], 'refund', $id);
                    return $this->success(null,
                        '退款金额 ' . $refund['amount'] . ' 元 ≥ 审批阈值 ' . $threshold . ' 元，已提交审批中心复核，通过后方可执行退款');
                }
                return $this->fail(4220, '该退款单已存在待复核的审批单，请等待审批中心处理');
            }
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

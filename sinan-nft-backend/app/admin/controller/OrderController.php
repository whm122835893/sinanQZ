<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台订单管理控制器
 *
 * 覆盖：订单列表/详情（聚合支付+资产）、取消订单、标记支付（复刻 C 端
 * 发售/市场两种支付事务）、发起退款、异常订单审计与导出。
 *
 * 资金规则（与 C 端严格一致）：
 * - 余额支付：钱包扣减 + buy 流水（direction=2）
 * - 市场成交：资产过户 + 卖家结算 actual_amount（reward 流水 direction=1）
 */
class OrderController extends BaseController
{
    /**
     * GET /admin/order/list
     * 筛选：orderNo、userId/userKeyword、collectibleId、status、source、时间区间
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('orders')->alias('o')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT');

        $orderNo = trim((string) $this->request->param('orderNo', ''));
        if ($orderNo !== '') {
            $query->whereLike('o.order_no', '%' . $orderNo . '%');
        }
        $userId = $this->positiveInt('userId');
        if ($userId !== null) {
            $query->where('o.user_id', $userId);
        }
        $userKeyword = trim((string) $this->request->param('userKeyword', ''));
        if ($userKeyword !== '') {
            $query->whereExists(function ($q) use ($userKeyword) {
                $q->name('users')->whereRaw('nft_users.id = o.user_id')
                  ->where(function ($q2) use ($userKeyword) {
                      $q2->whereLike('nft_users.phone', "%{$userKeyword}%")
                         ->whereOr('nft_users.uid', $userKeyword);
                  });
            });
        }
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) {
            $query->where('o.collectible_id', $collectibleId);
        }
        // 关键词搜索：订单号 / 用户（手机号/UID/用户名）/ 藏品名（AdminTablePage 统一发送 keyword）
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('o.order_no', '%' . $keyword . '%')
                    ->whereOr('u.username', 'like', '%' . $keyword . '%')
                    ->whereOr('u.uid', 'like', '%' . $keyword . '%')
                    ->whereOr('u.phone', 'like', '%' . $keyword . '%')
                    ->whereOr('c.name', 'like', '%' . $keyword . '%');
            });
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['pending', 'completed', 'cancelled', 'refunding', 'refunded'], true)) {
            $query->where('o.status', $status);
        }
        $source = (string) $this->request->param('source', '');
        if ($source !== '' && in_array($source, ['release', 'market', 'priority', 'eligibility'], true)) {
            $query->where('o.source', $source);
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('o.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('o.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('o.id, o.order_no, o.user_id, u.uid, u.username, u.phone, o.collectible_id, c.name AS collectible_name, c.image AS collectible_image,
                               o.unit_price, o.quantity, o.total_price, o.status, o.source,
                               o.created_at, o.paid_at, o.completed_at, o.expires_at')
            ->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $rows = array_map(function ($row) {
            $row['phone'] = $row['phone'] ? mask_phone((string) $row['phone']) : null;
            return $row;
        }, $rows);

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/order/detail/:id
     */
    public function detail(int $id)
    {
        $order = Db::name('orders')->alias('o')
            ->field('o.*, u.uid, u.username, u.phone, c.name AS collectible_name, c.image')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->where('o.id', $id)
            ->find();
        if (!$order) {
            return $this->fail(4040, '订单不存在');
        }

        $payment = Db::name('payments')->where('order_id', $id)->order('id', 'desc')->find();
        $ucs = Db::name('user_collectibles')->where('order_id', $id)
            ->field('id, serial, status, acquired_at')->select()->toArray();
        $refund = Db::name('refunds')->where('order_id', $id)->order('id', 'desc')->find();
        $listing = null;
        if ($order['resale_listing_id']) {
            $listing = Db::name('resale_listings')->where('id', $order['resale_listing_id'])->find();
            $listing['seller_uid'] = Db::name('users')->where('id', $listing['seller_id'])->value('uid');
        }

        $data = camelize_keys($order);
        $data['phone'] = mask_phone((string) $order['phone']);
        $data['payment'] = $payment ? camelize_keys($payment) : null;
        $data['userCollectibles'] = camelize_keys($ucs);
        $data['refund'] = $refund ? camelize_keys($refund) : null;
        $data['listing'] = $listing ? camelize_keys($listing) : null;

        return $this->success($data);
    }

    /**
     * POST /admin/order/cancel { id, reason }
     * 管理员取消待支付订单（释放锁定库存/恢复挂单）
     */
    public function cancel()
    {
        $missing = $this->missingParams(['id', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $reason = trim((string) $this->request->param('reason'));

        Db::startTrans();
        try {
            $order = Db::name('orders')->where('id', $id)->where('status', 'pending')->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(4220, '订单不存在或非待支付状态（仅待支付订单可取消）');
            }

            $now = date('Y-m-d H:i:s');
            Db::name('orders')->where('id', $id)->update([
                'status'        => 'cancelled',
                'cancelled_at'  => $now,
                'cancel_reason' => mb_substr('管理员取消：' . $reason, 0, 100),
                'updated_at'    => $now,
            ]);

            if ($order['source'] === 'release' || $order['source'] === 'priority' || $order['source'] === 'eligibility') {
                Db::name('collectibles')->where('id', $order['collectible_id'])
                    ->whereRaw('locked_quantity >= ' . (int) $order['quantity'])
                    ->update(['locked_quantity' => Db::raw('locked_quantity - ' . (int) $order['quantity']), 'updated_at' => $now]);
            } elseif ($order['resale_listing_id']) {
                Db::name('resale_listings')->where('id', $order['resale_listing_id'])
                    ->where('status', 'sold')
                    ->update(['status' => 'selling', 'updated_at' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '取消失败：' . $e->getMessage());
        }

        $this->audit('order', 'cancel', '取消订单 ' . $order['order_no'],
            ['reason' => $reason], 'order', $id);
        return $this->success(null, '订单已取消并释放占用资源');
    }

    /**
     * POST /admin/order/mark-paid { id, payment_method(balance/alipay/wechat), transaction_no? }
     * 标记支付成功（第三方支付确认到账场景；事务逻辑与 C 端支付完全一致）
     */
    public function markPaid()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $method = (string) $this->request->param('payment_method', 'alipay');
        if (!in_array($method, ['balance', 'alipay', 'wechat'], true)) {
            return $this->fail(4220, 'payment_method 仅允许 balance/alipay/wechat');
        }

        Db::startTrans();
        try {
            $order = Db::name('orders')->where('id', $id)->where('status', 'pending')->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(4220, '订单不存在或已处理');
            }

            $now = date('Y-m-d H:i:s');

            // 余额支付：扣钱包 + 流水
            if ($method === 'balance') {
                $wallet = Db::name('wallets')->where('user_id', $order['user_id'])->lock(true)->find();
                if (!$wallet || (float) $wallet['available'] < (float) $order['total_price']) {
                    Db::rollback();
                    return $this->fail(4220, '用户余额不足，无法标记余额支付');
                }
                Db::name('wallets')->where('user_id', $order['user_id'])->update([
                    'balance'    => Db::raw("balance - {$order['total_price']}"),
                    'available'  => Db::raw("available - {$order['total_price']}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $order['user_id'],
                    'trans_type'    => 'buy',
                    'title'         => '购买藏品',
                    'direction'     => 2,
                    'amount'        => $order['total_price'],
                    'balance_after' => (float) $wallet['available'] - (float) $order['total_price'],
                    'biz_no'        => $order['order_no'],
                    'created_at'    => $now,
                ]);
            }

            Db::name('payments')->insert([
                'order_id'       => $order['id'],
                'user_id'        => $order['user_id'],
                'amount'         => $order['total_price'],
                'payment_method' => $method,
                'transaction_no' => trim((string) $this->request->param('transaction_no', '')) ?: null,
                'status'         => 'success',
                'paid_at'        => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            if ($order['source'] === 'release' || $order['source'] === 'priority' || $order['source'] === 'eligibility') {
                // 发售模式：库存结转 + 生成持仓
                $collectible = Db::name('collectibles')->where('id', $order['collectible_id'])->lock(true)->find();
                Db::name('collectibles')->where('id', $order['collectible_id'])->update([
                    'sold'            => Db::raw("sold + {$order['quantity']}"),
                    'locked_quantity' => Db::raw("locked_quantity - {$order['quantity']}"),
                    'circulate'       => Db::raw("circulate + {$order['quantity']}"),
                    'updated_at'      => $now,
                ]);
                $soldPrev = (int) $collectible['sold'];
                for ($i = 0; $i < (int) $order['quantity']; $i++) {
                    $seq    = str_pad((string) ($soldPrev + $i + 1), 4, '0', STR_PAD_LEFT);
                    $serial = 'SN-' . $collectible['id'] . '-' . $seq;
                    Db::name('user_collectibles')->insert([
                        'user_id'        => $order['user_id'],
                        'collectible_id' => $order['collectible_id'],
                        'order_id'       => $order['id'],
                        'serial'         => $serial,
                        'source'         => 'purchase',
                        'acquired_price' => $order['unit_price'],
                        'acquired_at'    => $now,
                        'status'         => 'held',
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                }
            } else {
                // 市场模式：资产过户 + 卖家结算
                $listing = Db::name('resale_listings')->where('id', $order['resale_listing_id'])->lock(true)->find();
                if (!$listing || $listing['status'] !== 'sold') {
                    Db::rollback();
                    return $this->fail(4220, '挂单状态异常（可能已被其他订单处理）');
                }
                $moved = Db::name('user_collectibles')
                    ->where('id', $listing['user_collectible_id'])
                    ->where('user_id', $listing['seller_id'])
                    ->where('status', 'consigned')
                    ->update([
                        'user_id'        => $order['user_id'],
                        'status'         => 'held',
                        'is_consigned'   => 0,
                        'acquired_at'    => $now,
                        'acquired_price' => $listing['price'],
                        'source'         => 'purchase',
                        'updated_at'     => $now,
                    ]);
                if (!$moved) {
                    Db::rollback();
                    return $this->fail(4220, '藏品状态异常，过户失败');
                }
                $sellerWallet = Db::name('wallets')->where('user_id', $listing['seller_id'])->lock(true)->find();
                Db::name('wallets')->where('user_id', $listing['seller_id'])->update([
                    'balance'    => Db::raw("balance + {$listing['actual_amount']}"),
                    'available'  => Db::raw("available + {$listing['actual_amount']}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $listing['seller_id'],
                    'trans_type'    => 'reward',
                    'title'         => '寄售成交结算',
                    'direction'     => 1,
                    'amount'        => $listing['actual_amount'],
                    'balance_after' => (float) $sellerWallet['balance'] + (float) $listing['actual_amount'],
                    'biz_no'        => $order['order_no'],
                    'created_at'    => $now,
                ]);
            }

            Db::name('orders')->where('id', $order['id'])->update([
                'status'       => 'completed',
                'paid_at'      => $now,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '标记支付失败：' . $e->getMessage());
        }

        $this->audit('order', 'mark_paid', '标记订单支付成功 ' . $order['order_no'] . '（' . $method . '）',
            ['method' => $method], 'order', $id);
        return $this->success(null, '订单已完成支付与资产交割');
    }

    /**
     * POST /admin/order/refund { id, reason, amount? }
     * 发起退款：仅限已完成订单（发售来源；市场单涉及三方，走人工工单）
     */
    public function refund()
    {
        $missing = $this->missingParams(['id', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $reason = trim((string) $this->request->param('reason'));

        $order = Db::name('orders')->where('id', $id)->find();
        if (!$order) {
            return $this->fail(4040, '订单不存在');
        }
        if ($order['status'] !== 'completed') {
            return $this->fail(4220, '仅已完成支付的订单可发起退款（当前：' . $order['status'] . '）');
        }
        if ($order['source'] === 'market') {
            return $this->fail(4220, '市场寄售订单涉及买卖双方结算，请通过客服工单人工处理');
        }
        $existing = Db::name('refunds')->where('order_id', $id)->whereIn('status', [1, 2, 3])->count();
        if ($existing > 0) {
            return $this->fail(4220, '该订单已存在处理中/已完成的退款申请');
        }

        $amount = $this->request->param('amount');
        $amount = ($amount !== null && $amount !== '') ? (float) $amount : (float) $order['total_price'];
        if ($amount <= 0 || $amount > (float) $order['total_price']) {
            return $this->fail(4220, '退款金额不合法（0 < amount ≤ ' . $order['total_price'] . '）');
        }

        // 持仓校验：订单资产仍在用户名下（未被转赠/寄售）
        $heldCount = Db::name('user_collectibles')->where('order_id', $id)
            ->whereIn('status', ['held', 'frozen', 'consigned'])->count();
        $needCount = (int) Db::name('user_collectibles')->where('order_id', $id)->count();
        if ($heldCount < $needCount) {
            return $this->fail(4220, '订单资产已发生流转（转赠/消耗），不满足全额退款条件，请走人工工单');
        }

        $now      = date('Y-m-d H:i:s');
        $refundNo = 'RF' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $payment  = Db::name('payments')->where('order_id', $id)->where('status', 'success')->order('id', 'desc')->find();

        $refundId = (int) Db::name('refunds')->insertGetId([
            'refund_no'      => $refundNo,
            'order_id'       => $id,
            'payment_id'     => $payment ? (int) $payment['id'] : 0,
            'user_id'        => $order['user_id'],
            'amount'         => $amount,
            'reason'         => mb_substr($reason, 0, 255),
            'status'         => 1, // 待审批
            'applicant_id'   => $this->adminId(),
            'applicant_name' => $this->adminName(),
            'ip'             => (string) $this->request->ip(),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // 订单进入退款中
        Db::name('orders')->where('id', $id)->update(['status' => 'refunding', 'updated_at' => $now]);

        $this->audit('order', 'refund_create', '发起退款 ' . $refundNo . '（订单 ' . $order['order_no'] . '，金额 ' . $amount . '）',
            ['reason' => $reason, 'amount' => $amount], 'refund', $refundId);

        return $this->success(['refund_no' => $refundNo, 'refund_id' => $refundId], '退款申请已提交，等待财务审批');
    }

    /**
     * GET /admin/orders/audit
     * 异常订单审计：过期未取消、支付状态不一致、资产缺失；支持导出 CSV
     * （命名避免与 BaseController::audit() 审计日志方法签名冲突）
     */
    public function auditList()
    {
        $now = date('Y-m-d H:i:s');

        // 1. 已过期仍 pending
        $expired = Db::name('orders')->alias('o')
            ->field('o.id, o.order_no, o.user_id, u.uid, o.total_price, o.source, o.expires_at')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->where('o.status', 'pending')
            ->where('o.expires_at', '<', $now)
            ->order('o.expires_at', 'asc')
            ->limit(100)
            ->select()->toArray();

        // 2. completed 但无成功支付记录
        $noPayment = Db::name('orders')->alias('o')
            ->field('o.id, o.order_no, o.user_id, u.uid, o.total_price, o.completed_at')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->where('o.status', 'completed')
            ->whereNotExists(function ($q) {
                $q->name('payments')->whereRaw('nft_payments.order_id = o.id AND nft_payments.status = "success"');
            })
            ->order('o.id', 'desc')
            ->limit(100)
            ->select()->toArray();

        // 3. 发售单 completed 但持仓数不足
        $missingAssets = Db::name('orders')->alias('o')
            ->field('o.id, o.order_no, o.user_id, u.uid, o.quantity, o.total_price')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->where('o.status', 'completed')
            ->whereIn('o.source', ['release', 'priority', 'eligibility'])
            ->whereRaw('(SELECT COUNT(*) FROM nft_user_collectibles uc WHERE uc.order_id = o.id) < o.quantity')
            ->order('o.id', 'desc')
            ->limit(100)
            ->select()->toArray();

        // 4. refunding 但无退款记录（状态悬空）
        $refundDangling = Db::name('orders')->alias('o')
            ->field('o.id, o.order_no, o.user_id, u.uid, o.total_price, o.updated_at')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->where('o.status', 'refunding')
            ->whereNotExists(function ($q) {
                $q->name('refunds')->whereRaw('nft_refunds.order_id = o.id AND nft_refunds.status IN (1,2)');
            })
            ->limit(100)
            ->select()->toArray();

        $result = [
            'expired'     => camelize_keys($expired),
            'noPayment'   => camelize_keys($noPayment),
            'missingAssets' => camelize_keys($missingAssets),
            'refundDangling' => camelize_keys($refundDangling),
            'totalAbnormal' => count($expired) + count($noPayment) + count($missingAssets) + count($refundDangling),
        ];

        // CSV 导出
        if ((string) $this->request->param('export', '') === 'csv') {
            $filename = 'orders_audit_' . date('Ymd_His') . '.csv';
            $csv = "\xEF\xBB\xBFtype,order_id,order_no,uid,total_price,extra\n";
            foreach ($expired as $r) {
                $csv .= 'expired_pending,' . $r['id'] . ',' . $r['order_no'] . ',' . $r['uid'] . ',' . $r['total_price'] . ',过期时间 ' . $r['expires_at'] . "\n";
            }
            foreach ($noPayment as $r) {
                $csv .= 'no_payment_record,' . $r['id'] . ',' . $r['order_no'] . ',' . $r['uid'] . ',' . $r['total_price'] . ',完成时间 ' . $r['completed_at'] . "\n";
            }
            foreach ($missingAssets as $r) {
                $csv .= 'missing_assets,' . $r['id'] . ',' . $r['order_no'] . ',' . $r['uid'] . ',' . $r['total_price'] . ',购买 ' . $r['quantity'] . "\n";
            }
            foreach ($refundDangling as $r) {
                $csv .= 'refund_dangling,' . $r['id'] . ',' . $r['order_no'] . ',' . $r['uid'] . ',' . $r['total_price'] . "\n";
            }
            $this->audit('order', 'audit_export', '导出异常订单审计 CSV');
            return response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        return $this->success($result);
    }
}

<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use app\admin\service\InventoryService;
use think\facade\Db;
use think\Response;

/**
 * 退款审批控制器（文档 8.9，#76-#79）
 * 状态机：1待审批 → approve → 4已退款（批准即执行：回收资产 + 回退计数器 + 原路退款）
 *         1待审批 → reject  → 3已拒绝
 */
class Refunds extends AdminBase
{
    /**
     * #76 GET /refunds 退款列表（status/时间范围）
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('refunds')->alias('r')
            ->join('nft_orders o', 'o.id = r.order_id', 'LEFT')
            ->join('nft_users u', 'u.id = r.user_id', 'LEFT')
            ->join('nft_admin_users a', 'a.id = r.applicant_id', 'LEFT');

        $status = $this->intParam('status');
        if ($status > 0) {
            $query->where('r.status', $status);
        }
        $refundNo = $this->strParam('refundNo');
        if ($refundNo !== null && $refundNo !== '') {
            $query->whereLike('r.refund_no', '%' . $refundNo . '%');
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('r.created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('r.created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('r.*,o.order_no,u.username,u.phone,a.real_name AS applicant_name')
            ->order('r.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $statusMap = [1 => '待审批', 2 => '已批准', 3 => '已拒绝', 4 => '已退款'];

        $items = array_map(function (array $r) use ($statusMap) {
            return [
                'id'             => (int) $r['id'],
                'refundNo'       => $r['refund_no'],
                'orderId'        => (int) $r['order_id'],
                'orderNo'        => $r['order_no'] ?? '',
                'userId'         => (int) $r['user_id'],
                'username'       => $r['username'] ?? '',
                'phone'          => isset($r['phone']) ? mask_phone((string) $r['phone']) : '',
                'amount'         => number_format((float) $r['amount'], 2, '.', ''),
                'reason'         => (string) ($r['reason'] ?? ''),
                'status'         => (int) $r['status'],
                'statusText'     => $statusMap[(int) $r['status']] ?? (string) $r['status'],
                'applicantName'  => $r['applicant_name'] ?? '',
                'refundChannel'  => (string) ($r['refund_channel'] ?? ''),
                'createdAt'      => (string) $r['created_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * #77 GET /refunds/:id 退款详情（订单/支付/金额/申请人/审批人）
     */
    public function detail(int $id)
    {
        $refund = Db::name('refunds')->alias('r')
            ->join('nft_orders o', 'o.id = r.order_id', 'LEFT')
            ->join('nft_users u', 'u.id = r.user_id', 'LEFT')
            ->join('nft_admin_users a', 'a.id = r.applicant_id', 'LEFT')
            ->join('nft_admin_users ap', 'ap.id = r.approver_id', 'LEFT')
            ->where('r.id', $id)
            ->field('r.*,o.order_no,o.status AS order_status,o.source AS order_source,o.quantity,o.unit_price,o.total_price,
                     u.username,u.phone,a.real_name AS applicant_name,ap.real_name AS approver_name')
            ->find();
        if (!$refund) {
            return $this->fail(409, '退款单不存在');
        }

        $payment = Db::name('payments')->where('id', (int) $refund['payment_id'])->find();

        // 退款关联的资产回收状态（批准后资产已删除，此处展示回收统计）
        $assetCount = 0;
        if ((int) $refund['status'] === 1) {
            $assetCount = Db::name('user_collectibles')
                ->where('order_id', (int) $refund['order_id'])
                ->where('status', 'held')
                ->count();
        }

        $statusMap = [1 => '待审批', 2 => '已批准', 3 => '已拒绝', 4 => '已退款'];

        return $this->success([
            'id'            => (int) $refund['id'],
            'refundNo'      => $refund['refund_no'],
            'amount'        => number_format((float) $refund['amount'], 2, '.', ''),
            'reason'        => (string) ($refund['reason'] ?? ''),
            'status'        => (int) $refund['status'],
            'statusText'    => $statusMap[(int) $refund['status']] ?? (string) $refund['status'],
            'refundChannel' => (string) ($refund['refund_channel'] ?? ''),
            'applicant'     => ['id' => (int) $refund['applicant_id'], 'name' => $refund['applicant_name'] ?? ''],
            'approver'      => $refund['approver_id'] ? ['id' => (int) $refund['approver_id'], 'name' => $refund['approver_name'] ?? ''] : null,
            'approvedAt'    => $refund['approved_at'] ?: null,
            'refundedAt'    => $refund['refunded_at'] ?: null,
            'createdAt'     => (string) $refund['created_at'],
            'order'         => [
                'id'        => (int) $refund['order_id'],
                'orderNo'   => $refund['order_no'] ?? '',
                'status'    => $refund['order_status'] ?? '',
                'source'    => $refund['order_source'] ?? '',
                'quantity'  => (int) ($refund['quantity'] ?? 0),
                'unitPrice' => number_format((float) ($refund['unit_price'] ?? 0), 2, '.', ''),
                'totalPrice' => number_format((float) ($refund['total_price'] ?? 0), 2, '.', ''),
            ],
            'user'          => [
                'id'       => (int) $refund['user_id'],
                'username' => $refund['username'] ?? '',
                'phone'    => mask_phone((string) ($refund['phone'] ?? '')),
            ],
            'payment'       => $payment ? [
                'id'            => (int) $payment['id'],
                'method'        => $payment['payment_method'],
                'amount'        => number_format((float) $payment['amount'], 2, '.', ''),
                'status'        => $payment['status'],
                'paidAt'        => $payment['paid_at'],
            ] : null,
            'pendingAssets' => $assetCount, // 待审批时可回收的资产数（已批准则已删除）
        ]);
    }

    /**
     * #78 POST /refunds/:id/approve 批准退款（comment/password，回收资产 + 回退计数器）
     * 批准即执行：资产行删除（5.5 校验 held）→ InventoryService::revertOnRecover 回退计数器
     * → 原路退款（balance 加回余额写流水；alipay/wechat 视为线下原路退回）→ status=4
     */
    public function approve(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $comment = trim((string) $this->strParam('comment'));
        if ($comment === '') {
            return $this->invalid('审批意见不能为空');
        }

        Db::startTrans();
        try {
            $refund = Db::name('refunds')->where('id', $id)->lock(true)->find();
            if (!$refund) {
                Db::rollback();
                return $this->fail(409, '退款单不存在');
            }
            if ((int) $refund['status'] !== 1) {
                Db::rollback();
                return $this->conflict('仅待审批退款单可审批，当前状态为 ' . $this->statusText((int) $refund['status']));
            }

            $order = Db::name('orders')->where('id', (int) $refund['order_id'])->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(409, '关联订单不存在');
            }
            $payment = Db::name('payments')->where('id', (int) $refund['payment_id'])->find();
            if (!$payment) {
                Db::rollback();
                return $this->fail(409, '关联支付记录不存在');
            }

            $now = date('Y-m-d H:i:s.v');
            $userId = (int) $order['user_id'];

            // ---- 1. 回收资产（5.5：非 held 即拦截，明确原因）----
            $assets = $this->loadOrderAssets($order);
            $revertDetails = [];
            foreach ($assets as $uc) {
                if ($uc['status'] !== 'held') {
                    $statusText = ['consigned' => '正在寄售', 'frozen' => '转赠冻结中', 'transferred' => '已转赠', 'consumed' => '已消耗'][$uc['status']] ?? $uc['status'];
                    Db::rollback();
                    return $this->conflict("资产 #{$uc['id']}（{$uc['serial']}）{$statusText}，无法回收，请先处理用户资产");
                }
                // 计数器回退（4.3.4，外键优先溯源）
                $revertDetails[] = InventoryService::revertOnRecover($uc) + ['assetId' => (int) $uc['id']];
                // 资产行物理删除（审计 before 快照留痕）
                Db::name('user_collectibles')->where('id', (int) $uc['id'])->delete();
            }

            // ---- 2. 原路退款 ----
            $channel = (string) $payment['payment_method'];
            if ($channel === 'balance') {
                $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
                if (!$wallet) {
                    Db::rollback();
                    return $this->fail(409, '用户钱包不存在，无法原路退回余额');
                }
                Db::name('wallets')->where('user_id', $userId)->update([
                    'balance'    => Db::raw("balance + {$refund['amount']}"),
                    'available'  => Db::raw("available + {$refund['amount']}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $userId,
                    'trans_type'    => 'reward',
                    'title'         => '订单退款（' . $refund['refund_no'] . '）',
                    'direction'     => 1,
                    'amount'        => $refund['amount'],
                    'balance_after' => (float) $wallet['balance'] + (float) $refund['amount'],
                    'biz_no'        => $refund['refund_no'],
                    'created_at'    => $now,
                ]);
            }
            // alipay/wechat：线下原路退回，不动平台余额，refund_channel 留痕

            // ---- 3. 退款单闭环 ----
            Db::name('refunds')->where('id', $id)->update([
                'status'         => 4,
                'approver_id'    => $this->adminId(),
                'approved_at'    => $now,
                'refunded_at'    => $now,
                'refund_channel' => $channel,
                'updated_at'     => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '退款批准失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'refund', 'refund.approve', [
            'target_type' => 'refund',
            'target_id'   => $id,
            'target_desc' => $refund['refund_no'] . '（订单 ' . $order['order_no'] . '，金额 ' . number_format((float) $refund['amount'], 2, '.', '') . '）',
            'before'      => ['status' => 1],
            'after'       => ['status' => 4, 'channel' => $channel, 'recoveredAssets' => count($revertDetails)],
            'reason'      => $comment,
        ]);

        return $this->success([
            'refundNo'        => $refund['refund_no'],
            'channel'         => $channel,
            'recoveredAssets' => count($revertDetails),
            'revertDetails'   => $revertDetails,
        ], '退款已批准并执行（资产回收、计数器回退、原路退款）');
    }

    /**
     * #79 POST /refunds/:id/reject 拒绝退款（comment/password）
     */
    public function reject(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $comment = trim((string) $this->strParam('comment'));
        if ($comment === '') {
            return $this->invalid('审批意见不能为空');
        }

        Db::startTrans();
        try {
            $refund = Db::name('refunds')->where('id', $id)->lock(true)->find();
            if (!$refund) {
                Db::rollback();
                return $this->fail(409, '退款单不存在');
            }
            if ((int) $refund['status'] !== 1) {
                Db::rollback();
                return $this->conflict('仅待审批退款单可审批，当前状态为 ' . $this->statusText((int) $refund['status']));
            }

            $now = date('Y-m-d H:i:s.v');
            Db::name('refunds')->where('id', $id)->update([
                'status'      => 3,
                'approver_id' => $this->adminId(),
                'approved_at' => $now,
                'updated_at'  => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '退款拒绝失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'refund', 'refund.reject', [
            'target_type' => 'refund',
            'target_id'   => $id,
            'target_desc' => $refund['refund_no'],
            'before'      => ['status' => 1],
            'after'       => ['status' => 3],
            'reason'      => $comment,
        ]);

        return $this->success(null, '退款已拒绝');
    }

    // =====================================================================
    // 私有辅助
    // =====================================================================

    /**
     * 加载退款订单对应的有效资产行
     * release 单：按 order_id；market 单：按挂单的 user_collectible_id
     */
    private function loadOrderAssets(array $order): array
    {
        if ($order['source'] === 'release') {
            return Db::name('user_collectibles')
                ->where('order_id', (int) $order['id'])
                ->select()
                ->toArray();
        }

        $ucId = Db::name('resale_listings')
            ->where('id', (int) $order['resale_listing_id'])
            ->value('user_collectible_id');
        if (!$ucId) {
            return [];
        }
        $uc = Db::name('user_collectibles')->where('id', (int) $ucId)->find();
        return $uc ? [$uc] : [];
    }

    private function statusText(int $status): string
    {
        return [1 => '待审批', 2 => '已批准', 3 => '已拒绝', 4 => '已退款'][$status] ?? (string) $status;
    }
}

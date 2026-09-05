<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use think\facade\Db;
use think\Response;

/**
 * 订单管理控制器（文档 8.8，8 接口）
 *
 * 强制取消/标记已支付/修复均走与 C 端一致的库存计数器路径
 * （release：locked/sold/circulate；market：资产过户+卖家结算），防库存漂移。
 */
class Orders extends AdminBase
{
    /**
     * #68 GET /orders 订单列表
     * 筛选：orderNo/userId/status/source/type/时间范围
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('orders')->alias('o')
            ->join('nft_users u', 'u.id = o.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = o.collectible_id', 'LEFT');

        $orderNo = trim((string) $this->strParam('orderNo'));
        if ($orderNo !== '') {
            $query->where('o.order_no', 'like', "%{$orderNo}%");
        }
        $userId = $this->intParam('userId');
        if ($userId > 0) {
            $query->where('o.user_id', $userId);
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('o.status', $status);
        }
        $source = $this->strParam('source');
        if ($source !== null && $source !== '') {
            $query->where('o.source', $source);
        }
        // type：blindbox=盲盒订单 / collectible=普通藏品订单
        $type = $this->strParam('type');
        if ($type === 'blindbox') {
            $query->join('nft_blind_boxes bb', 'bb.collectible_id = o.collectible_id');
        } elseif ($type === 'collectible') {
            $query->whereNotExists(function ($q) {
                $q->name('blind_boxes')->whereRaw('nft_blind_boxes.collectible_id = o.collectible_id');
            });
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('o.created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('o.created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('o.id,o.order_no,o.user_id,o.collectible_id,o.resale_listing_id,o.unit_price,o.quantity,o.total_price,o.status,o.source,o.paid_at,o.created_at,u.username,u.phone,c.name AS collectible_name,c.image')
            ->order('o.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) {
            return $this->formatOrder($row);
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #69 GET /orders/:id 订单详情
     */
    public function detail(int $id)
    {
        $order = $this->findOrder($id);
        if (!$order) {
            return $this->fail(409, '订单不存在');
        }

        $result = $this->formatOrder($order, true);

        // 支付信息
        $payment = Db::name('payments')->where('order_id', $id)->find();
        $result['payment'] = $payment ? [
            'id'            => (int) $payment['id'],
            'amount'        => number_format((float) $payment['amount'], 2, '.', ''),
            'method'        => $payment['payment_method'],
            'transactionNo' => $payment['transaction_no'],
            'status'        => $payment['status'],
            'paidAt'        => $payment['paid_at'],
        ] : null;

        // 关联资产
        $assets = Db::name('user_collectibles')->where('order_id', $id)
            ->field('id,serial,status,source,acquired_price,acquired_at')
            ->select()->toArray();
        $result['assets'] = array_map(function ($a) {
            return [
                'id'           => (int) $a['id'],
                'serial'       => $a['serial'],
                'status'       => $a['status'],
                'source'       => $a['source'],
                'acquiredPrice' => number_format((float) $a['acquired_price'], 2, '.', ''),
                'acquiredAt'   => $a['acquired_at'],
            ];
        }, $assets);

        // 退款记录
        $refunds = Db::name('refunds')->where('order_id', $id)->order('id', 'desc')->select()->toArray();
        $result['refunds'] = array_map(function ($r) {
            return [
                'id'         => (int) $r['id'],
                'refundNo'   => $r['refund_no'],
                'amount'     => number_format((float) $r['amount'], 2, '.', ''),
                'status'     => (int) $r['status'],
                'reason'     => $r['reason'],
                'createdAt'  => $r['created_at'],
            ];
        }, $refunds);

        return $this->success($result);
    }

    /**
     * #70 POST /orders/:id/cancel 强制取消订单（reason/password，释放锁定库存）
     */
    public function cancel(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('强制取消原因不能为空');
        }

        Db::startTrans();
        try {
            $order = Db::name('orders')->where('id', $id)->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(409, '订单不存在');
            }
            if ($order['status'] !== 'pending') {
                Db::rollback();
                return $this->conflict("仅待支付订单可取消，当前状态为 {$order['status']}（已支付请走退款流程）");
            }

            $now = date('Y-m-d H:i:s');
            Db::name('orders')->where('id', $id)->update([
                'status'        => 'cancelled',
                'cancelled_at'  => $now,
                'cancel_reason' => $reason,
                'updated_at'    => $now,
            ]);

            $released = '无';
            if ($order['source'] === 'release') {
                // 与 C 端取消一致：条件释放锁定库存（防负数）
                $ok = Db::name('collectibles')
                    ->where('id', $order['collectible_id'])
                    ->whereRaw('locked_quantity >= ' . (int) $order['quantity'])
                    ->update([
                        'locked_quantity' => Db::raw('locked_quantity - ' . (int) $order['quantity']),
                        'updated_at'      => $now,
                    ]);
                $released = $ok ? "locked_quantity -{$order['quantity']}" : '锁定库存已为 0（无需释放）';
            } elseif ($order['resale_listing_id']) {
                Db::name('resale_listings')
                    ->where('id', $order['resale_listing_id'])
                    ->where('status', 'sold')
                    ->update(['status' => 'selling', 'updated_at' => $now]);
                $released = '挂单恢复在售';
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '取消失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'order', 'order.force_cancel', [
            'target_type' => 'order',
            'target_id'   => $id,
            'target_desc' => $order['order_no'],
            'before'      => ['status' => $order['status']],
            'after'       => ['status' => 'cancelled', 'released' => $released],
            'reason'      => $reason,
        ]);

        return $this->success(null, '订单已强制取消');
    }

    /**
     * #71 POST /orders/:id/mark-paid 标记已支付（reason/password，走 InventoryService 同计数器）
     * 场景：第三方支付回调丢失的补单。balance 方式扣余额；第三方方式视为已实际收款。
     */
    public function markPaid(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('标记支付原因不能为空');
        }
        $method = $this->strParam('method') ?? 'alipay';
        if (!in_array($method, ['balance', 'alipay', 'wechat'], true)) {
            return $this->invalid('支付方式必须为 balance/alipay/wechat');
        }

        Db::startTrans();
        try {
            $order = Db::name('orders')->where('id', $id)->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(409, '订单不存在');
            }
            if ($order['status'] !== 'pending') {
                Db::rollback();
                return $this->conflict("仅待支付订单可标记支付，当前状态为 {$order['status']}");
            }
            if (Db::name('payments')->where('order_id', $id)->count() > 0) {
                Db::rollback();
                return $this->conflict('该订单已存在支付记录，不可重复标记');
            }

            $now = date('Y-m-d H:i:s.v');
            $userId = (int) $order['user_id'];

            // balance：扣余额（与 C 端支付一致；第三方视为已实际收款）
            if ($method === 'balance') {
                $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
                if (!$wallet || (float) $wallet['available'] < (float) $order['total_price']) {
                    Db::rollback();
                    return $this->conflict('用户余额不足（' . number_format((float) ($wallet['available'] ?? 0), 2, '.', '') . '），无法以余额方式标记支付');
                }
                Db::name('wallets')->where('user_id', $userId)->update([
                    'balance'    => Db::raw("balance - {$order['total_price']}"),
                    'available'  => Db::raw("available - {$order['total_price']}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $userId,
                    'trans_type'    => 'buy',
                    'title'         => '购买藏品（管理端补单）',
                    'direction'     => 2,
                    'amount'        => $order['total_price'],
                    'balance_after' => (float) $wallet['available'] - (float) $order['total_price'],
                    'biz_no'        => $order['order_no'],
                    'created_at'    => $now,
                ]);
            }

            Db::name('payments')->insert([
                'order_id'       => $id,
                'user_id'        => $userId,
                'amount'         => $order['total_price'],
                'payment_method' => $method,
                'status'         => 'success',
                'paid_at'        => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $this->fulfillOrder($order, $userId, $now);

            Db::commit();
        } catch (\OrderConflictException $e) {
            Db::rollback();
            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '标记支付失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'order', 'order.mark_paid', [
            'target_type' => 'order',
            'target_id'   => $id,
            'target_desc' => $order['order_no'],
            'before'      => ['status' => 'pending'],
            'after'       => ['status' => 'completed', 'method' => $method],
            'reason'      => $reason,
        ]);

        return $this->success(null, '订单已标记支付并完成履约');
    }

    /**
     * #72 POST /orders/:id/refund 发起退款申请（amount/reason）
     */
    public function refund(int $id)
    {
        $order = $this->findOrder($id);
        if (!$order) {
            return $this->fail(409, '订单不存在');
        }
        if ($order['status'] !== 'completed') {
            return $this->conflict("仅已完成订单可发起退款，当前状态为 {$order['status']}");
        }

        $payment = Db::name('payments')->where('order_id', $id)->find();
        if (!$payment || $payment['status'] !== 'success') {
            return $this->conflict('订单无成功支付记录，无法退款');
        }

        $amount = $this->strParam('amount');
        if ($amount === null || !is_numeric($amount) || (float) $amount <= 0) {
            return $this->invalid('退款金额必须大于 0');
        }
        $amount = round((float) $amount, 2);
        if ($amount > (float) $payment['amount']) {
            return $this->conflict("退款金额不能超过实付金额 " . number_format((float) $payment['amount'], 2, '.', ''));
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('退款原因不能为空');
        }

        // 同订单存在未完结退款单时拦截
        $open = Db::name('refunds')
            ->where('order_id', $id)
            ->whereIn('status', [1, 2])
            ->count();
        if ($open > 0) {
            return $this->conflict('该订单已存在待审批/已批准的退款单，不可重复发起');
        }

        $now = date('Y-m-d H:i:s');
        $refundNo = 'TK' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $refundId = Db::name('refunds')->insertGetId([
            'refund_no'     => $refundNo,
            'order_id'      => $id,
            'payment_id'    => (int) $payment['id'],
            'user_id'       => (int) $order['user_id'],
            'amount'        => $amount,
            'reason'        => $reason,
            'status'        => 1,
            'applicant_id'  => $this->adminId(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        AuditLogService::log($this->request, 'order', 'order.refund.apply', [
            'target_type' => 'refund',
            'target_id'   => $refundId,
            'target_desc' => $refundNo . '（订单 ' . $order['order_no'] . '）',
            'after'       => ['amount' => $amount, 'reason' => $reason],
        ]);

        return $this->success(['refundId' => (int) $refundId, 'refundNo' => $refundNo], '退款申请已提交，待审批');
    }

    /**
     * #73 GET /orders/abnormal 异常订单列表
     * type：missing_asset（已支付无资产）/ duplicate_charge（支付成功但订单未完成）/
     *       amount_mismatch（支付金额与订单不符）
     */
    public function abnormal()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();
        $type = $this->strParam('type') ?? 'missing_asset';

        $query = Db::name('orders')->alias('o')
            ->join('nft_users u', 'u.id = o.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->join('nft_payments p', 'p.order_id = o.id', 'LEFT');

        switch ($type) {
            case 'missing_asset':
                // 已完成发售单但无资产行（或资产行数 < 数量）
                $query->where('o.status', 'completed')
                    ->where('o.source', 'release')
                    ->whereRaw('(SELECT COUNT(*) FROM nft_user_collectibles uc WHERE uc.order_id = o.id) < o.quantity');
                break;
            case 'duplicate_charge':
                // 支付成功但订单状态未流转（回调丢失）
                $query->where('o.status', 'pending')
                    ->where('p.status', 'success');
                break;
            case 'amount_mismatch':
                $query->whereRaw('p.amount IS NOT NULL AND p.amount <> o.total_price');
                break;
            default:
                return $this->invalid('type 必须为 missing_asset/duplicate_charge/amount_mismatch');
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('o.id,o.order_no,o.user_id,o.quantity,o.total_price,o.status,o.source,o.created_at,o.paid_at,u.username,c.name AS collectible_name,p.amount AS paid_amount,p.status AS payment_status')
            ->order('o.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) use ($type) {
            return [
                'id'             => (int) $row['id'],
                'orderNo'        => $row['order_no'],
                'username'       => $row['username'] ?? '',
                'collectibleName' => $row['collectible_name'] ?? '',
                'quantity'       => (int) $row['quantity'],
                'totalPrice'     => number_format((float) $row['total_price'], 2, '.', ''),
                'paidAmount'     => $row['paid_amount'] !== null ? number_format((float) $row['paid_amount'], 2, '.', '') : null,
                'paymentStatus'  => $row['payment_status'] ?? null,
                'status'         => $row['status'],
                'source'         => $row['source'],
                'abnormalType'   => $type,
                'createdAt'      => $row['created_at'],
                'paidAt'         => $row['paid_at'],
            ];
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #74 POST /orders/:id/repair 修复异常订单（repair_type/reason/password）
     * missing_asset：补齐缺失资产行（不动计数器，支付时计数器已更新）
     * duplicate_charge：按已成功支付补全订单履约（同 mark-paid 逻辑）
     * amount_mismatch：修正支付金额与订单一致
     */
    public function repair(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $repairType = $this->strParam('repairType');
        if (!in_array($repairType, ['missing_asset', 'duplicate_charge', 'amount_mismatch'], true)) {
            return $this->invalid('repairType 必须为 missing_asset/duplicate_charge/amount_mismatch');
        }
        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('修复原因不能为空');
        }

        Db::startTrans();
        try {
            $order = Db::name('orders')->where('id', $id)->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(409, '订单不存在');
            }
            $payment = Db::name('payments')->where('order_id', $id)->lock(true)->find();
            $now = date('Y-m-d H:i:s.v');

            switch ($repairType) {
                case 'missing_asset':
                    if ($order['status'] !== 'completed' || $order['source'] !== 'release') {
                        Db::rollback();
                        return $this->conflict('missing_asset 修复仅适用于已完成的发售订单');
                    }
                    $existing = Db::name('user_collectibles')->where('order_id', $id)->count();
                    $missing = (int) $order['quantity'] - (int) $existing;
                    if ($missing <= 0) {
                        Db::rollback();
                        return $this->conflict('该订单资产无缺失（已有 ' . $existing . ' 份）');
                    }
                    for ($i = 0; $i < $missing; $i++) {
                        Db::name('user_collectibles')->insert([
                            'user_id'        => $order['user_id'],
                            'collectible_id' => $order['collectible_id'],
                            'order_id'       => $id,
                            'serial'         => 'SN-' . $order['collectible_id'] . '-R' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                            'source'          => 'purchase',
                            'acquired_price'  => $order['unit_price'],
                            'acquired_at'     => $now,
                            'status'          => 'held',
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ]);
                    }
                    $result = ['repairedAssets' => $missing];
                    break;

                case 'duplicate_charge':
                    if ($order['status'] !== 'pending' || !$payment || $payment['status'] !== 'success') {
                        Db::rollback();
                        return $this->conflict('duplicate_charge 修复仅适用于「支付成功但订单待支付」场景');
                    }
                    $this->fulfillOrder($order, (int) $order['user_id'], $now);
                    $result = ['status' => 'completed'];
                    break;

                case 'amount_mismatch':
                    if (!$payment) {
                        Db::rollback();
                        return $this->conflict('该订单无支付记录');
                    }
                    if ((float) $payment['amount'] === (float) $order['total_price']) {
                        Db::rollback();
                        return $this->conflict('支付金额与订单金额一致，无需修复');
                    }
                    Db::name('payments')->where('id', $payment['id'])->update([
                        'amount'     => $order['total_price'],
                        'updated_at' => $now,
                    ]);
                    $result = [
                        'before' => number_format((float) $payment['amount'], 2, '.', ''),
                        'after'  => number_format((float) $order['total_price'], 2, '.', ''),
                    ];
                    break;

                default:
                    Db::rollback();
                    return $this->invalid('不支持的修复类型');
            }

            Db::commit();
        } catch (\OrderConflictException $e) {
            Db::rollback();
            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '修复失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'order', 'order.repair', [
            'target_type' => 'order',
            'target_id'   => $id,
            'target_desc' => $order['order_no'],
            'after'       => ['repairType' => $repairType, 'result' => $result],
            'reason'      => $reason,
        ]);

        return $this->success($result, '异常订单已修复');
    }

    /**
     * #75 GET /orders/export 导出订单（CSV 流式下载）
     */
    public function export()
    {
        $query = Db::name('orders')->alias('o')
            ->join('nft_users u', 'u.id = o.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = o.collectible_id', 'LEFT');

        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('o.status', $status);
        }
        $source = $this->strParam('source');
        if ($source !== null && $source !== '') {
            $query->where('o.source', $source);
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('o.created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('o.created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $rows = $query
            ->field('o.order_no,o.user_id,u.username,o.quantity,o.total_price,o.status,o.source,o.paid_at,o.created_at,c.name AS collectible_name')
            ->order('o.id', 'desc')
            ->limit(10000)
            ->select()
            ->toArray();

        $statusMap = ['pending' => '待支付', 'completed' => '已完成', 'cancelled' => '已取消'];
        $sourceMap = ['release' => '公售', 'market' => '市场', 'priority' => '优先购', 'eligibility' => '资格购'];

        $csv = "\xEF\xBB\xBF订单号,用户ID,用户名,藏品,数量,金额,状态,来源,支付时间,创建时间\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row['order_no'],
                $row['user_id'],
                '"' . str_replace('"', '""', (string) ($row['username'] ?? '')) . '"',
                '"' . str_replace('"', '""', (string) ($row['collectible_name'] ?? '')) . '"',
                $row['quantity'],
                number_format((float) $row['total_price'], 2, '.', ''),
                $statusMap[$row['status']] ?? $row['status'],
                $sourceMap[$row['source']] ?? $row['source'],
                $row['paid_at'] ?: '',
                $row['created_at'],
            ]) . "\n";
        }

        AuditLogService::log($this->request, 'order', 'order.export', [
            'target_type' => 'order',
            'target_desc' => '导出 ' . count($rows) . ' 条订单',
            'after'       => ['rows' => count($rows), 'filters' => array_filter([
                'status' => $status, 'source' => $source,
                'start' => $createdAtStart, 'end' => $createdAtEnd,
            ])],
        ]);

        return Response::create($csv, 'html')
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="orders_' . date('YmdHis') . '.csv"',
            ]);
    }

    // =====================================================================
    // 私有辅助
    // =====================================================================

    private function findOrder(int $id): ?array
    {
        return Db::name('orders')->alias('o')
            ->join('nft_users u', 'u.id = o.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->where('o.id', $id)
            ->field('o.*,u.username,u.phone,c.name AS collectible_name,c.image')
            ->find() ?: null;
    }

    /**
     * 订单履约（与 C 端支付逻辑一致：release 走计数器+资产，market 走过户+结算）
     * 供 mark-paid / duplicate_charge 修复共用
     */
    private function fulfillOrder(array $order, int $userId, string $now): void
    {
        // 订单状态流转
        Db::name('orders')->where('id', $order['id'])->update([
            'status'       => 'completed',
            'paid_at'      => $now,
            'completed_at' => $now,
            'updated_at'   => $now,
        ]);

        if ($order['source'] === 'release') {
            // 条件更新计数器（并发安全：与 C 端支付一致）
            $ok = Db::name('collectibles')
                ->where('id', $order['collectible_id'])
                ->whereRaw('sold + ' . (int) $order['quantity'] . ' + locked_quantity - ' . (int) $order['quantity']
                    . ' + reserved_count + airdropped_count + destroyed_count <= edition AND locked_quantity >= ' . (int) $order['quantity'])
                ->update([
                    'sold'            => Db::raw('sold + ' . (int) $order['quantity']),
                    'locked_quantity' => Db::raw('locked_quantity - ' . (int) $order['quantity']),
                    'circulate'       => Db::raw('circulate + ' . (int) $order['quantity']),
                    'updated_at'      => $now,
                ]);
            if (!$ok) {
                throw new \OrderConflictException('藏品库存校验失败（锁定不足或超发），无法完成履约');
            }

            // 生成资产
            $collectible = Db::name('collectibles')->where('id', $order['collectible_id'])->find();
            $soldPrev = (int) $collectible['sold'];
            for ($i = 0; $i < (int) $order['quantity']; $i++) {
                $seq = str_pad((string) ($soldPrev + $i + 1), 4, '0', STR_PAD_LEFT);
                Db::name('user_collectibles')->insert([
                    'user_id'        => $userId,
                    'collectible_id' => $order['collectible_id'],
                    'order_id'       => $order['id'],
                    'serial'         => 'SN-' . $order['collectible_id'] . '-' . $seq,
                    'source'         => 'purchase',
                    'acquired_price' => $order['unit_price'],
                    'acquired_at'    => $now,
                    'status'         => 'held',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        } else {
            // 市场单：资产过户 + 卖家结算
            $listing = Db::name('resale_listings')
                ->where('id', $order['resale_listing_id'])
                ->lock(true)
                ->find();
            if (!$listing || $listing['status'] !== 'sold') {
                throw new \OrderConflictException('挂单状态异常，无法完成履约');
            }
            $moved = Db::name('user_collectibles')
                ->where('id', $listing['user_collectible_id'])
                ->where('user_id', $listing['seller_id'])
                ->where('status', 'consigned')
                ->update([
                    'user_id'        => $userId,
                    'status'         => 'held',
                    'is_consigned'   => 0,
                    'acquired_at'    => $now,
                    'acquired_price' => $listing['price'],
                    'source'         => 'purchase',
                    'updated_at'     => $now,
                ]);
            if (!$moved) {
                throw new \OrderConflictException('藏品状态异常，无法完成履约');
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
                'title'         => '寄售成交结算（管理端补单）',
                'direction'     => 1,
                'amount'        => $listing['actual_amount'],
                'balance_after' => (float) $sellerWallet['balance'] + (float) $listing['actual_amount'],
                'biz_no'        => $order['order_no'],
                'created_at'    => $now,
            ]);
        }
    }

    private function formatOrder(array $order, bool $full = false): array
    {
        $row = [
            'id'             => (int) $order['id'],
            'orderNo'        => $order['order_no'],
            'userId'         => (int) $order['user_id'],
            'username'       => $order['username'] ?? '',
            'phone'          => isset($order['phone']) ? mask_phone((string) $order['phone']) : '',
            'collectibleId'  => (int) $order['collectible_id'],
            'collectibleName' => $order['collectible_name'] ?? '',
            'image'          => $order['image'] ?? '',
            'quantity'       => (int) $order['quantity'],
            'unitPrice'      => number_format((float) $order['unit_price'], 2, '.', ''),
            'totalPrice'     => number_format((float) $order['total_price'], 2, '.', ''),
            'status'         => $order['status'],
            'source'         => $order['source'],
            'paidAt'         => $order['paid_at'],
            'createdAt'      => $order['created_at'],
        ];
        if ($full) {
            $row['resaleListingId'] = $order['resale_listing_id'] !== null ? (int) $order['resale_listing_id'] : null;
            $row['completedAt']      = $order['completed_at'];
            $row['cancelledAt']      = $order['cancelled_at'];
            $row['cancelReason']     = $order['cancel_reason'];
        }
        return $row;
    }
}

/**
 * 订单履约业务冲突异常（事务内抛出，携带明确原因）
 */
class OrderConflictException extends \RuntimeException
{
}

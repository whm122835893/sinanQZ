<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 订单控制器
 * 创建订单 / 支付 / 取消 / 订单列表
 */
class Orders extends BaseController
{
    /**
     * POST /api/orders
     * 创建购买订单（发售 / 市场挂单）
     */
    public function create()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $collectibleId    = $this->intParam('collectibleId');
        $quantity         = $this->intParam('quantity', 1);
        $resaleListingId  = $this->intParam('resaleListingId');
        $no               = $this->strParam('no');
        $paymentPassword  = $this->request->post('paymentPassword', '');

        // 1. 校验交易密码
        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash) return $this->fail(2003, '请先设置交易密码');
        if (!verify_password($paymentPassword, $hash)) return $this->fail(2003, '交易密码错误');

        // 2. 实名前置
        $isRealname = Db::name('users')->where('id', $userId)->value('is_realname');
        if ((int) $isRealname !== 1) return $this->fail(1001, '请先完成实名认证');

        Db::startTrans();
        try {
            $now = date('Y-m-d H:i:s.v');

            if ($resaleListingId > 0) {
                // ===== 市场挂单购买 =====
                $listing = Db::name('resale_listings')
                    ->where('id', $resaleListingId)
                    ->where('status', 'selling')
                    ->lock(true)
                    ->find();
                if (!$listing) {
                    Db::rollback();
                    return $this->fail(1002, '挂单不存在或已售出');
                }
                if ((int) $listing['seller_id'] === $userId) {
                    Db::rollback();
                    return $this->fail(1001, '不能购买自己的藏品');
                }

                // 锁定卖家资产并校验归属与状态（资产仍在卖家名下 consigned，支付成功才过户）
                $userCollectible = Db::name('user_collectibles')
                    ->where('id', $listing['user_collectible_id'])
                    ->where('user_id', $listing['seller_id'])
                    ->where('status', 'consigned')
                    ->where('serial', $no ?: Db::raw('serial'))
                    ->lock(true)
                    ->find();
                if (!$userCollectible) {
                    Db::rollback();
                    return $this->fail(3001, '藏品状态异常，请重试');
                }

                // 挂单置 sold，阻止其他买家重复下单；资产保持卖家名下 consigned（escrow）
                Db::name('resale_listings')
                    ->where('id', $resaleListingId)
                    ->update(['status' => 'sold', 'updated_at' => $now]);

                $unitPrice   = $listing['price'];
                $totalPrice  = $listing['price'];
                $collectibleId = (int) $listing['collectible_id'];
                $source      = 'market';
                $soldNo      = $userCollectible['serial'];
            } else {
                // ===== 发售购买（含优先购/资格购判定链，文档 5.1/5.2，联动点 10.2）=====
                if ($quantity < 1) {
                    Db::rollback();
                    return $this->fail(1001, '数量必须大于0');
                }

                $collectible = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->whereNull('deleted_at')
                    ->lock(true)
                    ->find();
                if (!$collectible) {
                    Db::rollback();
                    return $this->fail(1002, '藏品不存在');
                }
                if ($collectible['status'] === 'soldout') {
                    Db::rollback();
                    return $this->fail(3002, '藏品已售罄');
                }

                $source = 'release';
                $nowTs  = time();

                // Step 1：有效优先购资格 = expires_at > now 且 used < max 且活动窗口内（行锁）
                // 注意 field 显式别名：两表均有 id/status 等同名列，PDO fetch 时后者覆盖前者，
                // 必须以 w.* + ps 别名字段返回，否则 $priority['id'] 会错拿到活动 ID
                $priority = Db::name('priority_sale_whitelists')->alias('w')
                    ->join('priority_sales ps', 'ps.id = w.priority_sale_id', 'INNER')
                    ->field('w.*,ps.id AS sale_id,ps.name AS sale_name,ps.start_time,ps.end_time')
                    ->where('ps.collectible_id', $collectibleId)
                    ->where('ps.status', 1)
                    ->where('w.user_id', $userId)
                    ->where('w.status', 1)
                    ->where('w.expires_at', '>', date('Y-m-d H:i:s'))
                    ->where('ps.start_time', '<=', date('Y-m-d H:i:s'))
                    ->where('ps.end_time', '>=', date('Y-m-d H:i:s'))
                    ->whereRaw('w.used_quantity < w.max_quantity')
                    ->lock(true)
                    ->find();
                if ($priority) {
                    // 优先购覆盖资格购限制；原子条件 UPDATE 防并发超用（used + N <= max）
                    $bumped = Db::name('priority_sale_whitelists')
                        ->where('id', $priority['id'])
                        ->whereRaw('used_quantity + ' . (int) $quantity . ' <= max_quantity')
                        ->update([
                            'used_quantity' => Db::raw('used_quantity + ' . (int) $quantity),
                            'updated_at'    => date('Y-m-d H:i:s'),
                        ]);
                    if (!$bumped) {
                        Db::rollback();
                        return $this->fail(3004, '优先购可购数量不足');
                    }
                    $source = 'priority';
                } else {
                    // Step 2/3：资格购判定（优先购不参与资格购）
                    $eligibility = \app\service\PurchaseQualifyService::checkEligibility($userId, $collectible);
                    if ($eligibility['enabled']) {
                        if (!$eligibility['qualified']) {
                            Db::rollback();
                            return $this->fail(3004, $eligibility['reason'] ?: '未获得购买资格');
                        }
                        $source = 'eligibility';
                    }

                    // 公售时间校验（优先购不受公售时间限制；onsale_at 为 NULL 表示不限）
                    if (!empty($collectible['onsale_at']) && strtotime($collectible['onsale_at']) > $nowTs) {
                        Db::rollback();
                        return $this->fail(1001, '尚未开售');
                    }
                }

                // 库存锁定（原子操作，受 CHECK 防超卖兜底）
                $affected = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->whereRaw('sold + locked_quantity + ' . (int) $quantity . ' <= edition')
                    ->update(['locked_quantity' => Db::raw("locked_quantity + {$quantity}")]);
                if (!$affected) {
                    Db::rollback();
                    return $this->fail(3001, '库存不足');
                }

                // 限购检查（藏品级 per_user_limit 非 0 时覆盖系统 purchase_limit_per_user，联动点 10.2）
                $limit = \app\service\PurchaseQualifyService::perUserLimit($collectible);
                $ownedCount = Db::name('orders')
                    ->where('user_id', $userId)
                    ->where('collectible_id', $collectibleId)
                    ->where('status', 'completed')
                    ->sum('quantity');
                if ($ownedCount + $quantity > $limit) {
                    Db::rollback();
                    return $this->fail(3003, "已达限购上限 {$limit}");
                }

                $unitPrice   = $collectible['price'];
                $totalPrice  = bcmul((string) $unitPrice, (string) $quantity, 2);
                $resaleListingId = null;
            }

            $orderNo = gen_order_no();

            Db::name('orders')->insert([
                'order_no'           => $orderNo,
                'user_id'            => $userId,
                'collectible_id'     => $collectibleId,
                'resale_listing_id'  => $resaleListingId ?: null,
                'unit_price'         => $unitPrice,
                'quantity'           => $quantity,
                'total_price'        => $totalPrice,
                'status'             => 'pending',
                'source'             => $source,
                'created_at'         => $now,
                'expires_at'         => date('Y-m-d H:i:s.v', time() + 300),
                'updated_at'         => $now,
            ]);

            Db::commit();

            $payments = [
                ['method' => 'balance', 'name' => '余额支付', 'balance' => (float) Db::name('wallets')->where('user_id', $userId)->value('available')],
                ['method' => 'alipay', 'name' => '支付宝'],
                ['method' => 'wechat', 'name' => '微信'],
            ];

            return $this->success([
                'orderNo'    => $orderNo,
                'source'     => $source,
                'totalPrice' => (float) $totalPrice,
                'expiresAt'  => date('Y-m-d H:i:s.v', time() + 300),
                'payments'   => $payments,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '下单失败：' . $e->getMessage());
        }
    }

    /**
     * POST /api/orders/:orderNo/pay
     * 支付订单
     */
    public function pay()
    {
        $userId     = $this->userId();
        $orderNo    = $this->request->param('orderNo');
        $method     = $this->request->post('paymentMethod', 'balance');
        $paymentPassword = $this->request->post('paymentPassword', '');

        if (!$userId) return $this->fail(2001, '未登录');
        if (!in_array($method, ['balance', 'alipay', 'wechat'])) {
            return $this->fail(1001, '支付方式不支持');
        }

        // 余额支付属于资金敏感操作，二次校验交易密码（区别于登录态）
        if ($method === 'balance') {
            $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
            if (!$hash || !verify_password($paymentPassword, $hash)) {
                return $this->fail(2003, '交易密码错误');
            }
        }

        Db::startTrans();
        try {
            $order = Db::name('orders')
                ->where('order_no', $orderNo)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(1002, '订单不存在');
            }
            if ($order['status'] !== 'pending') {
                Db::rollback();
                return $this->fail(4002, '订单已处理');
            }
            if (strtotime($order['expires_at']) < time()) {
                Db::rollback();
                return $this->fail(4002, '订单已过期');
            }

            $now = date('Y-m-d H:i:s.v');

            // 余额支付：扣钱包 + 写流水
            if ($method === 'balance') {
                $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
                if ((float) $wallet['available'] < (float) $order['total_price']) {
                    Db::rollback();
                    return $this->fail(4003, '余额不足');
                }
                Db::name('wallets')->where('user_id', $userId)->update([
                    'balance'     => Db::raw("balance - {$order['total_price']}"),
                    'available'   => Db::raw("available - {$order['total_price']}"),
                    'updated_at'  => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'        => $userId,
                    'trans_type'     => 'buy',
                    'title'          => '购买藏品',
                    'direction'      => 2,
                    'amount'         => $order['total_price'],
                    'balance_after'  => (float) $wallet['available'] - (float) $order['total_price'],
                    'biz_no'         => $orderNo,
                    'created_at'     => $now,
                ]);
            } else {
                // Mock：第三方支付直接成功
            }

            // 写支付记录
            Db::name('payments')->insert([
                'order_id'       => $order['id'],
                'user_id'        => $userId,
                'amount'         => $order['total_price'],
                'payment_method' => $method,
                'status'         => 'success',
                'paid_at'        => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            if ($order['source'] === 'release') {
                // ===== 发售模式：生成藏品 + 更新库存 =====
                $collectible = Db::name('collectibles')
                    ->where('id', $order['collectible_id'])
                    ->lock(true)
                    ->find();

                Db::name('collectibles')->where('id', $order['collectible_id'])->update([
                    'sold'            => Db::raw("sold + {$order['quantity']}"),
                    'locked_quantity' => Db::raw("locked_quantity - {$order['quantity']}"),
                    'circulate'       => Db::raw("circulate + {$order['quantity']}"),
                    'updated_at'      => $now,
                ]);

                // 生成 user_collectibles（行锁内基于 sold 序号，防并发重号）
                $edition  = (int) $collectible['edition'];
                $soldPrev = (int) $collectible['sold'];
                for ($i = 0; $i < $order['quantity']; $i++) {
                    $seq = str_pad((string) ($soldPrev + $i + 1), 4, '0', STR_PAD_LEFT);
                    $serial = "SN-{$collectible['id']}-{$seq}";
                    Db::name('user_collectibles')->insert([
                        'user_id'        => $userId,
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
                // ===== 市场模式：资产从卖家过户到买家 + 卖家结算 =====
                $listing = Db::name('resale_listings')
                    ->where('id', $order['resale_listing_id'])
                    ->lock(true)
                    ->find();
                if (!$listing || $listing['status'] !== 'sold') {
                    Db::rollback();
                    return $this->fail(3001, '挂单状态异常');
                }

                // 条件过户：仅当资产仍属卖家且为寄售状态（防并发/防篡改）
                $moved = Db::name('user_collectibles')
                    ->where('id', $listing['user_collectible_id'])
                    ->where('user_id', $listing['seller_id'])
                    ->where('status', 'consigned')
                    ->update([
                        'user_id'        => $userId,
                        'status'        => 'held',
                        'is_consigned'  => 0,
                        'acquired_at'   => $now,
                        'acquired_price'=> $listing['price'],
                        'source'        => 'purchase',
                        'updated_at'    => $now,
                    ]);
                if (!$moved) {
                    Db::rollback();
                    return $this->fail(3001, '藏品状态异常，请联系客服');
                }

                // 卖家结算：到账 = 挂单价 - 手续费
                $sellerWallet = Db::name('wallets')
                    ->where('user_id', $listing['seller_id'])
                    ->lock(true)
                    ->find();
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
                    'biz_no'        => $orderNo,
                    'created_at'    => $now,
                ]);
            }

            // 更新订单状态
            Db::name('orders')->where('id', $order['id'])->update([
                'status'       => 'completed',
                'paid_at'      => $now,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

            Db::commit();

            return $this->success([
                'orderNo' => $orderNo,
                'status'  => 'completed',
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '支付失败：' . $e->getMessage());
        }
    }

    /**
     * POST /api/orders/callback
     * 支付回调（第三方异步通知，此处简化为手动调用）
     */
    public function callback()
    {
        return $this->success();
    }

    /**
     * POST /api/orders/:orderNo/cancel
     * 取消订单
     */
    public function cancel()
    {
        $userId  = $this->userId();
        $orderNo = $this->request->param('orderNo');
        if (!$userId) return $this->fail(2001, '未登录');

        Db::startTrans();
        try {
            // 行锁：防止并发取消/支付双写（双取消会导致 locked_quantity 释放两次变负）
            $order = Db::name('orders')
                ->where('order_no', $orderNo)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->lock(true)
                ->find();
            if (!$order) {
                Db::rollback();
                return $this->fail(1002, '订单不存在或已处理');
            }

            $now = date('Y-m-d H:i:s.v');
            Db::name('orders')->where('id', $order['id'])->update([
                'status'        => 'cancelled',
                'cancelled_at'  => $now,
                'updated_at'    => $now,
            ]);

            if ($order['source'] === 'release') {
                // 条件释放锁定库存：仅当锁定量足够时扣减（配合 CHECK 防负数）
                Db::name('collectibles')
                    ->where('id', $order['collectible_id'])
                    ->whereRaw('locked_quantity >= ' . (int) $order['quantity'])
                    ->update([
                        'locked_quantity' => Db::raw("locked_quantity - {$order['quantity']}"),
                        'updated_at'      => $now,
                    ]);
            } elseif ($order['resale_listing_id']) {
                // 市场单：资产从未过户（支付时才过户），仅需恢复挂单在售
                Db::name('resale_listings')
                    ->where('id', $order['resale_listing_id'])
                    ->where('status', 'sold')
                    ->update(['status' => 'selling', 'updated_at' => $now]);
            }

            Db::commit();
            return $this->success();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '取消失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/orders
     * 我的订单列表
     */
    public function myList()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p      = $this->pagination();
        $status = $this->strParam('status');

        $query = Db::name('orders')->alias('o')
            ->join('collectibles c', 'c.id = o.collectible_id')
            ->where('o.user_id', $userId)
            ->order('o.created_at', 'desc');

        if ($status) $query->where('o.status', $status);

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            'o.order_no', 'o.source', 'o.status', 'o.unit_price',
            'o.quantity', 'o.total_price', 'o.created_at',
            'o.collectible_id', 'o.expires_at',
            'c.name', 'c.image',
        ])->select()->toArray();

        $items = array_map(fn ($o) => [
            'orderNo'       => $o['order_no'],
            'collectibleId' => (int) $o['collectible_id'],
            'source'        => $o['source'],
            'status'        => $o['status'],
            'name'          => $o['name'],
            'image'         => $o['image'],
            'price'         => (float) $o['unit_price'],
            'qty'           => (int) $o['quantity'],
            'totalPrice'    => (float) $o['total_price'],
            'createdAt'     => $o['created_at'],
            'expiresAt'     => $o['expires_at'],
        ], $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }
}

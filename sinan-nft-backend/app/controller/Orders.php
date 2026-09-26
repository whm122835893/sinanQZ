<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;
use app\service\ActivityRewardService;
use app\service\PaymentService;

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
                // P0 修复：仅 onsale 状态可购买。此前只拦截 soldout，导致 upcoming（未正式发售/未上架）
                // 的藏品可被直接下单。status 取值：upcoming 未发售 / onsale 发售中 / soldout 已售罄 / off 已下架。
                if ($collectible['status'] !== 'onsale') {
                    Db::rollback();
                    $code = $collectible['status'] === 'soldout' ? 3002 : 1001;
                    $msg = $collectible['status'] === 'soldout' ? '藏品已售罄'
                        : ($collectible['status'] === 'upcoming' ? '藏品尚未开售' : '藏品当前不可购买');
                    return $this->fail($code, $msg);
                }

                $source = 'release';
                $nowTs  = time();

                // ═══════════════════════════════════════════════════════════
                // 业务规则（用户明确要求）：
                //  发售渠道：公售 / 资格购 / 抽签购，三者独立（抽签购走 Raffle 独立接口）
                //  1. 优先购只作用于公售（提前购买）；资格购开启时优先购不生效
                //  2. 资格购只有两种路径能买：白名单命中 OR 满足配置条件
                //  3. 抽签购只有中签 OR 抽签白名单（必中）才能买
                //  4. 资格购白名单与抽签白名单互不相通
                // ═══════════════════════════════════════════════════════════

                // Step 1：资格购判定（渠道门槛，未通过直接拦截）
                $eligibility = \app\service\PurchaseQualifyService::checkEligibility($userId, $collectible);
                if ($eligibility['enabled']) {
                    if (!$eligibility['qualified']) {
                        Db::rollback();
                        return $this->fail(3004, $eligibility['reason'] ?: '未获得购买资格');
                    }
                }

                // Step 2：优先购资格——仅公售模式生效（资格购渠道内没有优先购）
                // 有效 = expires_at > now 且 used < max 且活动窗口内（行锁）
                // 注意 field 显式别名：两表均有 id/status 等同名列，PDO fetch 时后者覆盖前者，
                // 必须以 w.* + ps 别名字段返回，否则 $priority['id'] 会错拿到活动 ID
                $priority = null;
                if (!$eligibility['enabled']) {
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
                }

                if ($priority) {
                    // 优先购独立配额扣减（公售提前通道，只扣优先购专属配额）
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
                    // 公售时间校验（优先购不受公售时间限制；onsale_at 为 NULL 表示不限）
                    if (!empty($collectible['onsale_at']) && strtotime($collectible['onsale_at']) > $nowTs) {
                        Db::rollback();
                        return $this->fail(1001, '尚未开售');
                    }
                    if (!empty($collectible['off_sale_at']) && strtotime($collectible['off_sale_at']) < $nowTs) {
                        Db::rollback();
                        return $this->fail(1001, '售卖已结束');
                    }
                    if ($eligibility['enabled']) {
                        $source = 'eligibility';
                    }
                }

                // ─── 库存锁定（原子操作，三重保险防超卖） ───
                // 1) edition 总发行量 CHECK
                // 2) release_quantity 上架份数（分批发售开启时；NULL=全部上架）
                // 3) locked_quantity 条件更新（行级互斥）
                $rq = isset($collectible['release_quantity']) ? (int) $collectible['release_quantity'] : 0;
                $useReleaseQty = $rq > 0; // release_quantity > 0 才启用分批发售限制
                $quotaUpper = $useReleaseQty ? $rq : (int) $collectible['edition'];

                // 预校验：saleable 够不够（友好报错；原子更新里还有一层 WHERE 兜底）
                $saleable = \app\service\InventoryService::saleable($collectible);
                if ($quantity > $saleable) {
                    Db::rollback();
                    return $this->fail(3001,
                        $useReleaseQty
                            ? '本轮上架 ' . $rq . ' 份，可售仅剩 ' . $saleable . ' 份'
                            : '库存不足（可售 ' . $saleable . ' 份）'
                    );
                }

                $stockWhere = 'sold + locked_quantity + ' . (int) $quantity . ' <= ' . (int) $quotaUpper;
                $affected = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->whereRaw($stockWhere)
                    ->update(['locked_quantity' => Db::raw('locked_quantity + ' . (float)($quantity))]);
                if (!$affected) {
                    Db::rollback();
                    return $this->fail(3001, $useReleaseQty
                        ? '并发超出本轮上架份额（' . $rq . ' 份），请稍后再试'
                        : '库存不足，请稍后再试'
                    );
                }

                // 限购检查（藏品级 per_user_limit 非 0 时覆盖系统 purchase_limit_per_user，联动点 10.2）
                // M5 修复：统计 pending + completed 订单，防止挂多笔待支付单绕过限购
                $limit = \app\service\PurchaseQualifyService::perUserLimit($collectible);
                $ownedCount = Db::name('orders')
                    ->where('user_id', $userId)
                    ->where('collectible_id', $collectibleId)
                    ->whereIn('status', ['pending', 'completed'])
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

            // 支付方式：读取后台「系统设置→支付渠道」启用的渠道（余额附当前可用余额）
            $payments = [];
            foreach (PaymentService::availableMethods() as $pm) {
                $item = ['method' => $pm['method'], 'name' => $pm['name']];
                if ($pm['method'] === 'balance') {
                    $item['balance'] = (float) Db::name('wallets')->where('user_id', $userId)->value('available');
                }
                $payments[] = $item;
            }

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
     * GET /api/payments/available
     * C 端可用支付方式（后台「系统设置→支付渠道」启用的渠道，按 sort_order 排序）
     */
    public function paymentMethods()
    {
        return $this->success(PaymentService::availableMethods());
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
        if (!in_array($method, PaymentService::CODES, true)) {
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
                // M4 修复：dec 替代 Db::raw(float)
                $payAmount = (float) $order['total_price'];
                Db::name('wallets')->where('user_id', $userId)
                    ->dec('balance', $payAmount)
                    ->dec('available', $payAmount)
                    ->update(['updated_at' => $now]);
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
                // 第三方支付：读取后台「系统设置→支付渠道」的启停/密钥配置，
                // 开发联调（APP_DEBUG）mock 成功；生产在收银台 SDK 接入前明确拒绝
                [$thirdOk, $thirdMsg] = PaymentService::payThirdParty($method, $orderNo);
                if (!$thirdOk) {
                    Db::rollback();
                    return $this->fail(5001, $thirdMsg);
                }
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

            if (in_array($order['source'], ['release', 'priority', 'eligibility'], true)) {
                // ===== 发售模式：生成藏品 + 更新库存 =====
                $collectible = Db::name('collectibles')
                    ->where('id', $order['collectible_id'])
                    ->lock(true)
                    ->find();

                Db::name('collectibles')->where('id', $order['collectible_id'])->update([
                    'sold'            => Db::raw('sold + ' . (float)($order['quantity'])),
                    'locked_quantity' => Db::raw('locked_quantity - ' . (float)($order['quantity'])),
                    'circulate'       => Db::raw('circulate + ' . (float)($order['quantity'])),
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
                // 批量单：batch_listing_ids 逗号分隔多份挂单；普通市场单：单份 resale_listing_id
                $listingIds = [];
                if (!empty($order['batch_listing_ids'])) {
                    $listingIds = array_values(array_filter(array_map('intval', explode(',', $order['batch_listing_ids']))));
                } elseif ($order['resale_listing_id']) {
                    $listingIds = [(int) $order['resale_listing_id']];
                }
                if (!$listingIds) {
                    Db::rollback();
                    return $this->fail(3001, '挂单信息异常');
                }

                foreach ($listingIds as $lid) {
                    $listing = Db::name('resale_listings')
                        ->where('id', $lid)
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
                    // M4 修复：inc 替代 Db::raw(float)
                    $settleAmount = (float) $listing['actual_amount'];
                    Db::name('wallets')->where('user_id', $listing['seller_id'])
                        ->inc('balance', $settleAmount)
                        ->inc('available', $settleAmount)
                        ->update(['updated_at' => $now]);
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
            }

            // 更新订单状态
            Db::name('orders')->where('id', $order['id'])->update([
                'status'       => 'completed',
                'paid_at'      => $now,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

            Db::commit();

            // 支付完成 → 被邀请人完成条件（consume 产生消费记录）邀请活动结算（失败不影响支付）
            ActivityRewardService::settleQuietly(
                fn () => ActivityRewardService::settleInviteReward($userId)
            );

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

            if (in_array($order['source'], ['release', 'priority', 'eligibility'], true)) {
                // 条件释放锁定库存：仅当锁定量足够时扣减（配合 CHECK 防负数）
                $affected = Db::name('collectibles')
                    ->where('id', $order['collectible_id'])
                    ->whereRaw('locked_quantity >= ' . (int) $order['quantity'])
                    ->update([
                        'locked_quantity' => Db::raw('locked_quantity - ' . (float)($order['quantity'])),
                        'updated_at'      => $now,
                    ]);
                if (!$affected) {
                    Db::rollback();
                    return $this->fail(5001, '库存锁定量异常（可能已被并发释放），请联系管理员');
                }

                // 优先购单：回退白名单配额（下单即扣 used_quantity，取消未回退会导致反复下单-取消耗尽配额）
                if ($order['source'] === 'priority') {
                    $wlId = Db::name('priority_sale_whitelists')->alias('w')
                        ->join('priority_sales ps', 'ps.id = w.priority_sale_id', 'INNER')
                        ->where('ps.collectible_id', $order['collectible_id'])
                        ->where('w.user_id', $userId)
                        ->value('w.id');
                    if ($wlId) {
                        Db::name('priority_sale_whitelists')
                            ->where('id', $wlId)
                            ->whereRaw('used_quantity >= ' . (int) $order['quantity'])
                            ->update([
                                'used_quantity' => Db::raw('used_quantity - ' . (int) $order['quantity']),
                                'updated_at'    => date('Y-m-d H:i:s'),
                            ]);
                    }
                }
            } else {
                // 市场单：资产从未过户（支付时才过户），仅需恢复挂单在售
                // 批量单恢复 batch_listing_ids 内全部挂单；普通市场单仅恢复单份
                $listingIds = [];
                if (!empty($order['batch_listing_ids'])) {
                    $listingIds = array_values(array_filter(array_map('intval', explode(',', $order['batch_listing_ids']))));
                } elseif ($order['resale_listing_id']) {
                    $listingIds = [(int) $order['resale_listing_id']];
                }
                foreach ($listingIds as $lid) {
                    Db::name('resale_listings')
                        ->where('id', $lid)
                        ->where('status', 'sold')
                        ->update(['status' => 'selling', 'updated_at' => $now]);
                }
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

        $total = (clone $query)->count();
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

    /**
     * GET /api/airdrops/mine
     * 当前用户收到的空投发放记录（从 airdrop_records 表查）
     * —— 展示在"我的订单"页面的「空投」tab 里
     */
    public function airdropMine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p = $this->pagination();

        $query = Db::name('airdrop_records')->alias('ar')
            ->join('collectibles c', 'c.id = ar.collectible_id')
            ->where('ar.user_id', $userId)
            ->order('ar.id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->limit($p['offset'], $p['pageSize'])->field([
            'ar.id', 'ar.task_id', 'ar.phone', 'ar.collectible_id',
            'ar.quantity', 'ar.status', 'ar.issued_at', 'ar.created_at',
            'c.name', 'c.image',
        ])->select()->toArray();

        $items = array_map(fn ($r) => [
            'id'            => (int) $r['id'],
            'taskId'        => (int) $r['task_id'],
            'phone'         => $r['phone'],
            'collectibleId' => (int) $r['collectible_id'],
            'name'          => $r['name'],
            'image'         => $r['image'],
            'quantity'      => (int) $r['quantity'],
            'status'        => $r['status'],   // issued / failed / pending
            'issuedAt'      => $r['issued_at'],
            'createdAt'     => $r['created_at'],
        ], $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }
}

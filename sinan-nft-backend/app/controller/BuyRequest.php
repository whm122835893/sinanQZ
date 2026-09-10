<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 求购挂单（C 端）
 * 对应 nft_buy_requests 表；状态：1=求购中 2=已接单 3=已关闭 4=已成交 5=已过期
 */
class BuyRequest extends BaseController
{
    /**
     * GET /api/buy-requests?collectibleId=
     * 某藏品的求购挂单列表（仅求购中 status=1）
     */
    public function list()
    {
        $p             = $this->pagination();
        $collectibleId = $this->intParam('collectibleId');

        $query = Db::name('buy_requests')->alias('br')
            ->join('users u', 'u.id = br.user_id', 'LEFT')
            ->where('br.status', 1)
            ->whereNull('br.deleted_at');
        if ($collectibleId > 0) {
            $query->where('br.collectible_id', $collectibleId);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('br.price', 'desc')
            ->order('br.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'br.id', 'br.collectible_id', 'br.user_id', 'br.price',
                'br.quantity', 'br.status', 'br.remark', 'br.expires_at',
                'br.created_at',
                'u.username', 'u.phone',
            ])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'collectibleId' => (int) $r['collectible_id'],
                'userId'        => (int) $r['user_id'],
                'userName'      => $r['username'] ? mask_name((string) $r['username']) : '匿名用户',
                'price'         => (float) $r['price'],
                'quantity'      => (int) $r['quantity'],
                'status'        => (int) $r['status'],
                'remark'        => $r['remark'] ?? '',
                'expiresAt'     => $r['expires_at'],
                'createdAt'     => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/buy-requests
     * 发布求购挂单（需登录）
     */
    public function create()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $collectibleId = $this->intParam('collectibleId');
        $price         = (float) $this->request->post('price', 0);
        $quantity      = max(1, $this->intParam('quantity', 1));
        $remark        = $this->strParam('remark', '');

        if ($collectibleId <= 0) {
            return $this->fail(1001, '请选择求购藏品');
        }
        if ($price <= 0) {
            return $this->fail(1001, '求购单价必须大于 0');
        }

        $collectible = Db::name('collectibles')->where('id', $collectibleId)->find();
        if (!$collectible) {
            return $this->fail(1001, '藏品不存在');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);

        $id = Db::name('buy_requests')->insertGetId([
            'collectible_id' => $collectibleId,
            'user_id'        => $userId,
            'price'          => $price,
            'quantity'       => $quantity,
            'status'         => 1,
            'remark'         => $remark,
            'expires_at'     => $expiresAt,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'id'            => (int) $id,
            'collectibleId' => $collectibleId,
            'price'         => $price,
            'quantity'      => $quantity,
            'status'        => 1,
            'expiresAt'     => $expiresAt,
        ]);
    }

    /**
     * POST /api/buy-requests/:id/accept
     * 卖家接单（需登录，需持有该藏品）
     * B08 修复：原实现仅置状态为已接单，无订单生成、无资金结算、无持仓过户；
     * 现按建表设计（order_no 字段）接单自动创建订单并即时成交
     */
    public function accept()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $id = $this->intParam('id');
        if ($id <= 0) {
            return $this->fail(1001, '求购单不存在');
        }

        Db::startTrans();
        try {
            $br = Db::name('buy_requests')->where('id', $id)->lock(true)->find();
            if (!$br || $br['status'] != 1) {
                Db::rollback();
                return $this->fail(1001, '求购单不存在或已被接单');
            }

            // 不能接自己发布的求购
            $buyerId = (int) $br['user_id'];
            if ($buyerId === (int) $userId) {
                Db::rollback();
                return $this->fail(1001, '不能接自己发布的求购');
            }

            $qty        = max(1, (int) $br['quantity']);
            $unitPrice  = (float) $br['price'];
            $totalPrice = round($unitPrice * $qty, 2);

            // 卖家需足额持有该藏品（锁定具体资产行，防并发重复接单/转赠/寄售）
            $assets = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $br['collectible_id'])
                ->where('status', 'held')
                ->limit($qty)
                ->lock(true)
                ->column('id');
            if (count($assets) < $qty) {
                Db::rollback();
                return $this->fail(1001, "您持有的该藏品不足 {$qty} 件，无法接单");
            }

            // 求购方余额校验（发布求购未冻结资金，接单时需可即时支付）
            $buyerWallet = Db::name('wallets')->where('user_id', $buyerId)->lock(true)->find();
            if (!$buyerWallet || (float) $buyerWallet['available'] < $totalPrice) {
                Db::rollback();
                return $this->fail(1001, '求购方余额不足，无法接单');
            }

            $now     = date('Y-m-d H:i:s.v');
            $orderNo = gen_order_no();

            // 生成订单（即时成交，C2C 二级市场来源）
            Db::name('orders')->insert([
                'order_no'          => $orderNo,
                'user_id'           => $buyerId,
                'collectible_id'    => $br['collectible_id'],
                'resale_listing_id' => null,
                'unit_price'        => $unitPrice,
                'quantity'          => $qty,
                'total_price'       => $totalPrice,
                'status'            => 'completed',
                'source'            => 'market',
                'created_at'        => $now,
                'paid_at'           => $now,
                'completed_at'      => $now,
                'expires_at'        => $now,
                'updated_at'        => $now,
            ]);
            $orderId = (int) Db::name('orders')->getLastInsID();

            // 写支付记录
            Db::name('payments')->insert([
                'order_id'       => $orderId,
                'user_id'        => $buyerId,
                'amount'         => $totalPrice,
                'payment_method' => 'balance',
                'status'         => 'success',
                'paid_at'        => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            // 买家扣款 + 流水
            Db::name('wallets')->where('user_id', $buyerId)->update([
                'balance'    => Db::raw("balance - {$totalPrice}"),
                'available'  => Db::raw("available - {$totalPrice}"),
                'updated_at' => $now,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $buyerId,
                'trans_type'    => 'buy',
                'title'         => '求购成交购买',
                'direction'     => 2,
                'amount'        => $totalPrice,
                'balance_after' => (float) $buyerWallet['available'] - $totalPrice,
                'biz_no'        => $orderNo,
                'created_at'    => $now,
            ]);

            // 卖家结算（按市场手续费率计费，与寄售成交口径一致）
            $feeRate = (float) Db::name('system_configs')
                ->where('config_key', 'resale_fee_rate')
                ->value('config_value');
            $feeRate      = $feeRate ?: 1.0;
            $feeAmount    = round($totalPrice * $feeRate / 100, 2);
            $actualAmount = round($totalPrice - $feeAmount, 2);

            $sellerWallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
            Db::name('wallets')->where('user_id', $userId)->update([
                'balance'    => Db::raw("balance + {$actualAmount}"),
                'available'  => Db::raw("available + {$actualAmount}"),
                'updated_at' => $now,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $userId,
                'trans_type'    => 'reward',
                'title'         => '求购成交结算',
                'direction'     => 1,
                'amount'        => $actualAmount,
                'balance_after' => (float) $sellerWallet['balance'] + $actualAmount,
                'biz_no'        => $orderNo,
                'created_at'    => $now,
            ]);

            // 资产过户（条件更新：仅当资产仍属卖家且为 held）
            $serials = Db::name('user_collectibles')->whereIn('id', $assets)->column('serial');
            $moved = Db::name('user_collectibles')
                ->whereIn('id', $assets)
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->update([
                    'user_id'        => $buyerId,
                    'status'         => 'held',
                    'source'         => 'purchase',
                    'acquired_at'    => $now,
                    'acquired_price' => $unitPrice,
                    'is_consigned'   => 0,
                    'updated_at'     => $now,
                ]);
            if (!$moved || $moved < $qty) {
                Db::rollback();
                return $this->fail(3001, '藏品状态异常，接单失败');
            }

            // 求购单置为已成交并回写订单号
            Db::name('buy_requests')->where('id', $id)->update([
                'status'       => 4,
                'accepted_by'  => $userId,
                'accepted_at'  => date('Y-m-d H:i:s'),
                'order_no'     => $orderNo,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '接单失败：' . $e->getMessage());
        }

        return $this->success([
            'id'       => (int) $id,
            'status'   => 4,
            'orderNo'  => $orderNo,
            'nos'      => array_values($serials),
        ]);
    }
}
